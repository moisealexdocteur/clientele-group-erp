<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use App\Support\AutoParts\ProductRules;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modification d'un article. Un changement de prix est journalisé avec
 * l'ancien et le nouveau prix ; les ventes passées gardent leur prix.
 */
final class UpdateProduct extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly ProductRules $rules,
        private readonly PartsLookup $lookup,
        private readonly PartsPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $product): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->lookup->productFor($company, $product);

        $this->rules->canonicalize($request);
        $data = $request->validate($this->rules->rules($company, $model->id), $this->rules->messages());

        $retail = Money::normalize((string) $data['retail_price_htg']);
        $wholesale = isset($data['wholesale_price_htg']) ? Money::normalize((string) $data['wholesale_price_htg']) : null;
        $this->rules->assertPrices($retail, $wholesale);

        $before = [
            'retail_price_htg' => (string) $model->retail_price_htg,
            'wholesale_price_htg' => $model->wholesale_price_htg === null ? null : (string) $model->wholesale_price_htg,
        ];

        $model->forceFill([
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
            'updated_by' => $actor?->id,
        ])->save();

        $after = ['retail_price_htg' => $retail, 'wholesale_price_htg' => $wholesale];
        $this->audit->record(
            eventType: $before === $after ? 'parts.product_updated' : 'parts.product_price_changed',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: PartsProduct::class,
            subjectId: $model->id,
            metadata: ['sku' => $model->sku, 'before' => $before, 'after' => $after],
        );

        return response()->json([
            'data' => $this->presenter->productPayload($model->refresh(), $access, $this->lookup->stockSites($company, $access)),
        ]);
    }
}
