<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Article actif ou retiré de la vente. Un article retiré garde son historique et son stock. */
final class UpdateProductActive extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
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

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        if ($model->is_active !== (bool) $data['is_active']) {
            $model->forceFill(['is_active' => (bool) $data['is_active'], 'updated_by' => $actor?->id])->save();
            $this->audit->record(
                eventType: $model->is_active ? 'parts.product_activated' : 'parts.product_deactivated',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: PartsProduct::class,
                subjectId: $model->id,
                metadata: ['sku' => $model->sku],
            );
        }

        return response()->json([
            'data' => $this->presenter->productPayload($model, $access, $this->lookup->stockSites($company, $access)),
        ]);
    }
}
