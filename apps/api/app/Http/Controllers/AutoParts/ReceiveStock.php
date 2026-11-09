<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Models\PartsStockMovement;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use App\Support\AutoParts\StockLedger;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entrée de stock : arrivage ou réception fournisseur dans un magasin ou un
 * entrepôt, avec la référence du bon ou de la facture et le coût unitaire
 * lorsqu'il est connu.
 */
final class ReceiveStock extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly PartsPresenter $presenter,
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_cost_htg' => ['nullable', new DecimalAmount()],
        ], [
            'site_id.required' => 'Choisissez le magasin ou l’entrepôt qui reçoit les pièces.',
            'reference.required' => 'Saisissez la référence du bon ou de la facture fournisseur.',
            'lines.required' => 'Ajoutez au moins un article.',
            'lines.min' => 'Ajoutez au moins un article.',
            'lines.max' => '100 articles au plus par entrée.',
            'lines.*.product_id.distinct' => 'Un article apparaît deux fois. Regroupez les quantités sur une seule ligne.',
            'lines.*.quantity.required' => 'Saisissez la quantité reçue.',
            'lines.*.quantity.integer' => 'La quantité est un nombre entier de pièces.',
            'lines.*.quantity.min' => 'La quantité reçue doit être d’au moins 1.',
        ]);

        $site = $this->lookup->stockSiteFor($company, $access, $data['site_id']);
        $reference = trim($data['reference']);
        $note = Text::nullableTrimmed($data['note'] ?? null);

        $movements = DB::transaction(function () use ($company, $site, $data, $reference, $note, $actor): array {
            $movements = [];

            foreach ($data['lines'] as $index => $line) {
                $product = PartsProduct::query()->where('company_id', $company->id)->whereKey($line['product_id'])->first();

                if ($product === null) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => 'Article introuvable dans le catalogue.']);
                }

                $movements[] = $this->ledger->apply($product, $site, (int) $line['quantity'], 'receipt', $actor, [
                    'unit_cost_htg' => isset($line['unit_cost_htg']) ? Money::normalize((string) $line['unit_cost_htg']) : null,
                    'reference' => $reference,
                    'note' => $note,
                ], "lines.{$index}.quantity");
            }

            $this->audit->record(
                eventType: 'parts.stock_received',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                metadata: [
                    'site_id' => $site->id,
                    'reference' => $reference,
                    'lines' => count($movements),
                    'quantity' => array_sum(array_map(static fn (PartsStockMovement $movement): int => $movement->quantity, $movements)),
                ],
            );

            return $movements;
        });

        return response()->json([
            'data' => array_map(fn (PartsStockMovement $movement): array => $this->presenter->movementPayload($movement), $movements),
        ], 201);
    }
}
