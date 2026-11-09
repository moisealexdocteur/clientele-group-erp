<?php

namespace App\Http\Controllers\AutoParts;

use App\Support\AutoParts\PartsCustomerBook;
use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\PartsProduct;
use App\Models\PartsSale;
use App\Models\PartsSaleLine;
use App\Models\Site;
use App\Models\User;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\SalePresenter;
use App\Support\AutoParts\StockLedger;
use App\Support\CashSessionService;
use App\Support\DocumentNumberService;
use App\Support\Money;
use App\Support\ReceiptService;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vente au comptoir réglée en espèces HTG.
 *
 * - Les prix viennent du catalogue, jamais du poste.
 * - La caisse du magasin doit être ouverte ; l'encaissement devient un
 *   mouvement de sa session.
 * - Chaque pièce sort de l'emplacement choisi (magasin ou entrepôt), sans
 *   stock négatif.
 * - L'identifiant est fourni par le poste : renvoyer la même vente ne la
 *   crée pas une seconde fois.
 */
final class StoreSale extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly SalePresenter $presenter,
        private readonly StockLedger $ledger,
        private readonly CashSessionService $cash,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly PartsCustomerBook $customers,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'site_id' => ['required', 'uuid'],
            'cash_register_id' => ['required', 'uuid'],
            'price_list' => ['required', Rule::in(PartsSale::PRICE_LISTS)],
            'customer_profile_id' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40', 'regex:/\A\+?[0-9 ()-]{7,40}\z/'],
            'amount_received' => ['required', new DecimalAmount()],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.stock_site_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ], [
            'site_id.required' => 'Choisissez le magasin de la vente.',
            'cash_register_id.required' => 'Choisissez la caisse qui encaisse.',
            'amount_received.required' => 'Saisissez le montant reçu en HTG.',
            'lines.required' => 'Ajoutez au moins un article.',
            'lines.min' => 'Ajoutez au moins un article.',
            'lines.max' => '100 lignes au plus par vente.',
            'lines.*.quantity.integer' => 'La quantité est un nombre entier.',
            'lines.*.quantity.min' => 'La quantité doit être d’au moins 1.',
            'customer_phone.regex' => 'Saisissez un numéro de téléphone valide, par exemple +509 3700 0000.',
        ]);

        $existing = PartsSale::query()->where('company_id', $company->id)->whereKey($data['id'])->first();
        if ($existing !== null) {
            return response()->json(['data' => $this->presenter->detail($existing, $company)]);
        }

        if ($data['price_list'] === 'wholesale' && ! $access->allows('parts.sales.wholesale')) {
            return response()->json(['message' => 'Votre rôle ne permet pas d’appliquer le prix de gros.'], 403);
        }

        $store = $this->lookup->storeFor($company, $access, $data['site_id']);
        $register = CashRegister::query()
            ->where('company_id', $company->id)
            ->where('site_id', $store->id)
            ->where('is_active', true)
            ->whereKey($data['cash_register_id'])
            ->first();

        if ($register === null) {
            throw ValidationException::withMessages(['cash_register_id' => 'Cette caisse n’appartient pas au magasin choisi.']);
        }

        $lines = $this->resolveLines($company, $access, $data['lines'], $data['price_list']);
        $total = array_sum(array_column($lines, 'line_cents'));
        $received = Money::toCents((string) $data['amount_received']);

        if ($received < $total) {
            throw ValidationException::withMessages([
                'amount_received' => sprintf('Montant reçu insuffisant. Le total est de %s HTG.', Money::formatFr(Money::fromCents($total))),
            ]);
        }

        try {
            $sale = $this->record($company, $store, $register, $data, $lines, $total, $received, $actor);
        } catch (UniqueConstraintViolationException $exception) {
            // Même vente envoyée deux fois au même instant : la première est rendue.
            $existing = PartsSale::query()->where('company_id', $company->id)->whereKey($data['id'])->first();
            if ($existing === null) {
                throw $exception;
            }

            return response()->json(['data' => $this->presenter->detail($existing, $company)]);
        }

        return response()->json(['data' => $this->presenter->detail($sale, $company)], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{product: PartsProduct, site: Site, quantity: int, unit_cents: int, line_cents: int}>  $lines
     */
    private function record(Company $company, Site $store, CashRegister $register, array $data, array $lines, int $total, int $received, ?User $actor): PartsSale
    {
        return DB::transaction(function () use ($company, $store, $register, $data, $lines, $total, $received, $actor): PartsSale {
            $session = $this->cash->requireOpenSession($company, $register->id, 'cash_register_id');
            $now = now()->utc();
            // Client identifié si une fiche est choisie ou si nom et téléphone sont saisis.
            $customer = $this->customers->resolve($company, [
                'customer_profile_id' => $data['customer_profile_id'] ?? null,
                'name' => $data['customer_name'] ?? null,
                'phone' => $data['customer_phone'] ?? null,
            ]);

            $sale = PartsSale::query()->create([
                'id' => $data['id'],
                'company_id' => $company->id,
                'site_id' => $store->id,
                'cash_register_id' => $register->id,
                'cash_session_id' => $session->id,
                'number' => $this->numbers->next($company->id, ReceiptService::SEQUENCE_SCOPE),
                'price_list' => $data['price_list'],
                'customer_profile_id' => $customer?->id,
                'customer_name' => Text::nullableTrimmed($data['customer_name'] ?? null) ?? $customer?->display_name,
                'status' => 'completed',
                'currency' => 'HTG',
                'item_count' => array_sum(array_column($lines, 'quantity')),
                'total' => Money::fromCents($total),
                'amount_received' => Money::fromCents($received),
                'change_given' => Money::fromCents($received - $total),
                'sold_by' => $actor?->id,
                'sold_at' => $now,
            ]);

            // Verrouillage du stock dans un ordre constant pour éviter les blocages entre deux ventes.
            $order = array_keys($lines);
            usort($order, static fn (int $a, int $b): int => strcmp(
                $lines[$a]['product']->id . $lines[$a]['site']->id,
                $lines[$b]['product']->id . $lines[$b]['site']->id,
            ));

            foreach ($order as $index) {
                $line = $lines[$index];
                $this->ledger->apply($line['product'], $line['site'], -$line['quantity'], 'sale', $actor, [
                    'reference' => 'Reçu ' . $this->numbers->display($sale->number),
                    'sale_id' => $sale->id,
                ], "lines.{$index}.quantity");

                PartsSaleLine::query()->create([
                    'company_id' => $company->id,
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'stock_site_id' => $line['site']->id,
                    'sku' => $line['product']->sku,
                    'name' => $line['product']->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => Money::fromCents($line['unit_cents']),
                    'line_total' => Money::fromCents($line['line_cents']),
                ]);
            }

            CashMovement::query()->create([
                'company_id' => $company->id,
                'cash_session_id' => $session->id,
                'kind' => 'parts_sale',
                'direction' => 'in',
                'currency' => 'HTG',
                'amount' => Money::fromCents($total),
                'sale_id' => $sale->id,
                'recorded_by' => $actor?->id,
                'occurred_at' => $now,
            ]);

            $this->audit->record(
                eventType: 'parts.sale_completed',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: PartsSale::class,
                subjectId: $sale->id,
                metadata: [
                    'number' => $this->numbers->display($sale->number),
                    'site_id' => $store->id,
                    'cash_register_id' => $register->id,
                    'cash_session_id' => $session->id,
                    'price_list' => $sale->price_list,
                    'item_count' => $sale->item_count,
                    'total_htg' => Money::fromCents($total),
                ],
            );

            return $sale;
        });
    }

    /**
     * Article actif, emplacement de stock autorisé et prix du catalogue.
     *
     * @param  array<int, array{product_id: string, stock_site_id: string, quantity: int}>  $input
     * @return array<int, array{product: PartsProduct, site: Site, quantity: int, unit_cents: int, line_cents: int}>
     */
    private function resolveLines(Company $company, CompanyUserAccess $access, array $input, string $priceList): array
    {
        $seen = [];
        $lines = [];

        foreach ($input as $index => $line) {
            $key = $line['product_id'] . '|' . $line['stock_site_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => 'Cet article apparaît deux fois pour le même emplacement. Regroupez les quantités.',
                ]);
            }
            $seen[$key] = true;

            $product = PartsProduct::query()->where('company_id', $company->id)->whereKey($line['product_id'])->first();
            if ($product === null || ! $product->is_active) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => 'Cet article n’est pas en vente. Retirez-le du panier.',
                ]);
            }

            $price = $priceList === 'wholesale' ? $product->wholesale_price_htg : $product->retail_price_htg;
            if ($price === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => sprintf('%s n’a pas de prix de gros. Vendez-le au détail ou ajoutez son prix de gros.', $product->sku),
                ]);
            }

            $site = $this->lookup->stockSiteFor($company, $access, $line['stock_site_id'], "lines.{$index}.stock_site_id");
            $unit = Money::toCents((string) $price);
            $quantity = (int) $line['quantity'];

            $lines[] = [
                'product' => $product,
                'site' => $site,
                'quantity' => $quantity,
                'unit_cents' => $unit,
                'line_cents' => $unit * $quantity,
            ];
        }

        return $lines;
    }
}
