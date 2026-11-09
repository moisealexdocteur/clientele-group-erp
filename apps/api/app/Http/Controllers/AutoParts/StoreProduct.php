<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use App\Support\AutoParts\ProductRules;
use App\Support\AutoParts\StockLedger;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nouvel article. Un stock initial peut être saisi en même temps : il entre
 * dans le journal comme une entrée de stock « Stock initial ».
 */
final class StoreProduct extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly ProductRules $rules,
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

        $this->rules->canonicalize($request);
        $data = $request->validate([
            ...$this->rules->rules($company),
            'initial_site_id' => ['nullable', 'uuid', 'required_with:initial_quantity'],
            'initial_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'initial_unit_cost_htg' => ['nullable', new DecimalAmount()],
        ], [
            ...$this->rules->messages(),
            'initial_site_id.required_with' => 'Choisissez l’emplacement du stock initial.',
            'initial_quantity.integer' => 'Le stock initial est un nombre entier de pièces.',
            'initial_quantity.min' => 'Le stock initial doit être d’au moins 1, ou laissé vide.',
        ]);

        $retail = Money::normalize((string) $data['retail_price_htg']);
        $wholesale = isset($data['wholesale_price_htg']) ? Money::normalize((string) $data['wholesale_price_htg']) : null;
        $this->rules->assertPrices($retail, $wholesale);

        $initialSite = null;
        if (isset($data['initial_quantity']) || isset($data['initial_site_id'])) {
            if (! isset($data['initial_quantity'])) {
                throw ValidationException::withMessages(['initial_quantity' => 'Saisissez la quantité du stock initial, ou retirez l’emplacement.']);
            }
            abort_unless($access->allows('parts.stock.manage'), 403, 'Votre rôle ne permet pas de saisir du stock.');
            $initialSite = $this->lookup->stockSiteFor($company, $access, (string) $data['initial_site_id'], 'initial_site_id');
        }

        $product = DB::transaction(function () use ($company, $data, $retail, $wholesale, $actor, $initialSite): PartsProduct {
            $product = PartsProduct::query()->create([
                'company_id' => $company->id,
                'sku' => $data['sku'],
                'barcode' => $data['barcode'] ?? null,
                'name' => trim($data['name']),
                'category' => Text::nullableTrimmed($data['category'] ?? null),
                'brand' => Text::nullableTrimmed($data['brand'] ?? null),
                'unit' => trim($data['unit']),
                'retail_price_htg' => $retail,
                'wholesale_price_htg' => $wholesale,
                'reorder_level' => (int) $data['reorder_level'],
                'notes' => Text::nullableTrimmed($data['notes'] ?? null),
                'is_active' => true,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->audit->record(
                eventType: 'parts.product_created',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: PartsProduct::class,
                subjectId: $product->id,
                metadata: ['sku' => $product->sku, 'retail_price_htg' => $retail, 'wholesale_price_htg' => $wholesale],
            );

            if ($initialSite !== null) {
                $this->ledger->apply($product, $initialSite, (int) $data['initial_quantity'], 'receipt', $actor, [
                    'unit_cost_htg' => isset($data['initial_unit_cost_htg']) ? Money::normalize((string) $data['initial_unit_cost_htg']) : null,
                    'reference' => 'Stock initial',
                ], 'initial_quantity');
            }

            return $product;
        });

        return response()->json([
            'data' => $this->presenter->productPayload($product, $access, $this->lookup->stockSites($company, $access)),
        ], 201);
    }
}
