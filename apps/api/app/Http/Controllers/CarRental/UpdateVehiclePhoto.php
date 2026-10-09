<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\FileVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateVehiclePhoto extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly FileVault $files,
    ) {
    }

    /** Associe une photo réelle, déjà téléversée, à la fiche véhicule. */
    public function __invoke(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->lookup->vehicleFor($company, $access, $vehicle);

        $data = $request->validate([
            'file_id' => ['nullable', 'uuid'],
        ]);

        $photo = ($data['file_id'] ?? null) === null
            ? null
            : $this->files->find($company, $data['file_id'], StoredFile::PURPOSE_VEHICLE_PHOTO, 'file_id');

        $model->forceFill(['photo_file_id' => $photo?->id])->save();
        $this->audit->record(
            eventType: $photo === null ? 'car_rental.vehicle_photo_removed' : 'car_rental.vehicle_photo_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalVehicle::class,
            subjectId: $model->id,
            metadata: ['site_id' => $model->site_id],
        );

        return response()->json([
            'data' => $this->presenter->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }
}
