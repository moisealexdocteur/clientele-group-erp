<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalVehicleRules;
use App\Support\CompanySiteAuthorizer;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreVehicle extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalVehicleRules $vehicleRules,
        private readonly AuditLogger $audit,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $request->merge([
            'registration_number' => $this->vehicleRules->canonicalVehicleIdentifier($request->input('registration_number')),
            'vin' => $this->vehicleRules->canonicalVehicleIdentifier($request->input('vin')),
        ]);

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'category' => ['required', Rule::in(CarRentalVehicle::CATEGORIES)],
            'operational_status' => ['nullable', Rule::in(CarRentalVehicle::OPERATIONAL_STATUSES)],
            'make' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'model_year' => ['nullable', 'integer', 'between:1900,2100'],
            'registration_number' => [
                'required',
                'string',
                'max:32',
                Rule::unique('car_rental_vehicles', 'registration_number')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
                Rule::unique('car_rental_vehicles', 'code')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'registration_status' => ['required', Rule::in(CarRentalVehicle::REGISTRATION_STATUSES)],
            'reference_photo_key' => ['nullable', 'string', Rule::in(array_keys(CarRentalVehicle::REFERENCE_PHOTOS))],
            'vin' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('car_rental_vehicles', 'vin')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'latest_odometer_km' => ['required', 'integer', 'min:0'],
            'daily_rate_usd' => ['required', 'numeric', 'gt:0'],
            'minimum_security_deposit_usd' => ['required', 'numeric', 'gte:0'],
            ...$this->vehicleRules->vehicleContractRules(),
        ], $this->vehicleRules->vehicleValidationMessages());

        $site = $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        $vehicle = CarRentalVehicle::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $data['registration_number'],
            'category' => $data['category'],
            'operational_status' => $data['operational_status'] ?? 'available',
            'make' => Text::nullableTrimmed($data['make'] ?? null),
            'model' => Text::nullableTrimmed($data['model'] ?? null),
            'model_year' => $data['model_year'] ?? null,
            'registration_number' => $data['registration_number'],
            'registration_status' => $data['registration_status'],
            'reference_photo_key' => $data['reference_photo_key'] ?? null,
            'vin' => Text::nullableTrimmed($data['vin'] ?? null),
            'latest_odometer_km' => $data['latest_odometer_km'],
            'daily_rate_usd' => $data['daily_rate_usd'],
            'minimum_security_deposit_usd' => $data['minimum_security_deposit_usd'],
            ...$this->vehicleRules->vehicleContractAttributes($data),
            'is_active' => true,
        ]);

        $this->audit->record(
            eventType: 'car_rental.vehicle_created',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalVehicle::class,
            subjectId: $vehicle->id,
            metadata: [
                'code' => $vehicle->code,
                'site_id' => $vehicle->site_id,
                'category' => $vehicle->category,
                'operational_status' => $vehicle->operational_status,
                'daily_rate_usd' => $vehicle->daily_rate_usd,
                'minimum_security_deposit_usd' => $vehicle->minimum_security_deposit_usd,
                'has_reference_photo' => $vehicle->reference_photo_key !== null,
            ],
        );

        return response()->json([
            'data' => $this->presenter->vehiclePayload($vehicle->load(['site', 'documents']), true, $company),
        ], 201);
    }
}
