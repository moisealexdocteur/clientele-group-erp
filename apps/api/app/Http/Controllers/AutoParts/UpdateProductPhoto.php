<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use App\Support\FileVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Associe une photo réelle, déjà téléversée, à l'article ; sans fichier, la photo est retirée. */
final class UpdateProductPhoto extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly PartsPresenter $presenter,
        private readonly FileVault $files,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $product): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->lookup->productFor($company, $product);

        $data = $request->validate(['file_id' => ['nullable', 'uuid']]);
        $photo = ($data['file_id'] ?? null) === null
            ? null
            : $this->files->find($company, $data['file_id'], StoredFile::PURPOSE_PARTS_PRODUCT_PHOTO, 'file_id');

        $model->forceFill(['photo_file_id' => $photo?->id, 'updated_by' => $actor?->id])->save();
        $this->audit->record(
            eventType: $photo === null ? 'parts.product_photo_removed' : 'parts.product_photo_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: PartsProduct::class,
            subjectId: $model->id,
            metadata: ['sku' => $model->sku],
        );

        return response()->json([
            'data' => $this->presenter->productPayload($model, $access, $this->lookup->stockSites($company, $access)),
        ]);
    }
}
