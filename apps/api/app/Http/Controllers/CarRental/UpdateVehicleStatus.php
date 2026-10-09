<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateVehicleStatus extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'operational_status' => ['required', Rule::in(CarRentalVehicle::OPERATIONAL_STATUSES)],
        ]);

        $model = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($vehicle)
            ->first();

        abort_if($model === null, 404, 'Véhicule introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        $previousStatus = $model->operational_status;
        $model->forceFill(['operational_status' => $data['operational_status']])->save();

        if ($previousStatus !== $model->operational_status) {
            $this->audit->record(
                eventType: 'car_rental.vehicle_status_changed',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: CarRentalVehicle::class,
                subjectId: $model->id,
                metadata: [
                    'code' => $model->code,
                    'site_id' => $model->site_id,
                    'previous_status' => $previousStatus,
                    'operational_status' => $model->operational_status,
                ],
            );
        }

        return response()->json([
            'data' => $this->presenter->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }
}
