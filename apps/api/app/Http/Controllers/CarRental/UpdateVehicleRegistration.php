<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Models\CarRentalVehicleRegistrationEvent;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalVehicleRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UpdateVehicleRegistration extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalVehicleRules $vehicleRules,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $request->merge([
            'registration_number' => $this->vehicleRules->canonicalVehicleIdentifier($request->input('registration_number')),
        ]);

        $model = $this->lookup->vehicleFor($company, $access, $vehicle);
        $data = $request->validate([
            'registration_number' => [
                'required',
                'string',
                'max:32',
                Rule::unique('car_rental_vehicles', 'registration_number')
                    ->ignore($model->id)
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
                Rule::unique('car_rental_vehicles', 'code')
                    ->ignore($model->id)
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'registration_status' => ['required', Rule::in(CarRentalVehicle::REGISTRATION_STATUSES)],
        ], $this->vehicleRules->vehicleValidationMessages());

        $previousNumber = (string) ($model->registration_number ?: $model->code);
        $previousStatus = $model->registration_status ?: 'normal';

        if ($previousNumber !== $data['registration_number'] || $previousStatus !== $data['registration_status']) {
            DB::transaction(function () use ($company, $model, $actor, $data, $previousNumber, $previousStatus): void {
                CarRentalVehicleRegistrationEvent::query()->create([
                    'company_id' => $company->id,
                    'vehicle_id' => $model->id,
                    'previous_registration_number' => $previousNumber,
                    'current_registration_number' => $data['registration_number'],
                    'previous_registration_status' => $previousStatus,
                    'current_registration_status' => $data['registration_status'],
                    'changed_by' => $actor?->id,
                    'changed_at' => now()->utc(),
                ]);

                $model->forceFill([
                    'code' => $data['registration_number'],
                    'registration_number' => $data['registration_number'],
                    'registration_status' => $data['registration_status'],
                ])->save();
            });

            $this->audit->record(
                eventType: 'car_rental.vehicle_registration_changed',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: CarRentalVehicle::class,
                subjectId: $model->id,
                metadata: [
                    'site_id' => $model->site_id,
                    'previous_registration_status' => $previousStatus,
                    'registration_status' => $model->registration_status,
                ],
            );
        }

        return response()->json([
            'data' => $this->presenter->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }
}
