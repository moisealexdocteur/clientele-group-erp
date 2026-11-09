<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoModel;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoPresenter;
use App\Support\FileVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Associe une photo générique, déjà téléversée, au modèle ; sans fichier, la photo est retirée. */
final class UpdateMotoModelPhoto extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly MotoPresenter $presenter,
        private readonly FileVault $files,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $model): JsonResponse
    {
        $company = $this->company($request);
        $actor = $request->user();
        $record = MotoModel::query()->where('company_id', $company->id)->whereKey($model)->first();
        abort_if($record === null, 404, 'Modèle introuvable.');

        $data = $request->validate(['file_id' => ['nullable', 'uuid']]);
        $photo = ($data['file_id'] ?? null) === null
            ? null
            : $this->files->find($company, $data['file_id'], StoredFile::PURPOSE_MOTO_MODEL_PHOTO, 'file_id');

        $record->forceFill(['photo_file_id' => $photo?->id])->save();
        $this->audit->record(
            eventType: $photo === null ? 'parts.moto_model_photo_removed' : 'parts.moto_model_photo_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: MotoModel::class,
            subjectId: $record->id,
            metadata: ['model' => $record->model],
        );

        return response()->json(['data' => $this->presenter->modelPayload($record)]);
    }
}
