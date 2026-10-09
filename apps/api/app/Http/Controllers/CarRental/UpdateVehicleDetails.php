<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalVehicleRules;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateVehicleDetails extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalVehicleRules $vehicleRules,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Identité du véhicule imprimée sur le contrat : marque, modèle, couleur, motorisation. */
    public function __invoke(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->lookup->vehicleFor($company, $access, $vehicle);

        // Le numéro de série n'est modifié que s'il est envoyé.
        $vinProvided = $request->exists('vin');
        $request->merge(['vin' => $this->vehicleRules->canonicalVehicleIdentifier($request->input('vin'))]);
        $data = $request->validate([
            'category' => ['required', Rule::in(CarRentalVehicle::CATEGORIES)],
            'make' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'model_year' => ['nullable', 'integer', 'between:1900,2100'],
            'vin' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('car_rental_vehicles', 'vin')
                    ->ignore($model->id)
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'latest_odometer_km' => ['required', 'integer', 'min:0'],
            ...$this->vehicleRules->vehicleContractRules(),
        ], $this->vehicleRules->vehicleValidationMessages());

        $model->forceFill([
            'category' => $data['category'],
            'make' => Text::nullableTrimmed($data['make'] ?? null),
            'model' => Text::nullableTrimmed($data['model'] ?? null),
            'model_year' => $data['model_year'] ?? null,
            'vin' => $vinProvided ? Text::nullableTrimmed($data['vin'] ?? null) : $model->vin,
            'latest_odometer_km' => $data['latest_odometer_km'],
            ...$this->vehicleRules->vehicleContractAttributes($data),
        ]);
        $changed = array_keys($model->getDirty());
        $model->save();

        if ($changed !== []) {
            $this->audit->record(
                eventType: 'car_rental.vehicle_details_updated',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: CarRentalVehicle::class,
                subjectId: $model->id,
                metadata: ['site_id' => $model->site_id, 'changed' => $changed],
            );
        }

        return response()->json([
            'data' => $this->presenter->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }
}
