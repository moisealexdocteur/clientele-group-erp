<?php

namespace App\Http\Controllers;

use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CarRentalVehicle;
use App\Models\CarRentalVehicleDocument;
use App\Models\CarRentalVehicleRegistrationEvent;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CustomerIdentity;
use App\Models\CustomerProfile;
use App\Models\Site;
use App\Support\AuditLogger;
use App\Support\CarRentalAvailabilityService;
use App\Support\CompanySiteAuthorizer;
use App\Support\DocumentNumberService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CarRentalController extends Controller
{
    private const AIRPORT_SERVICE_FEE_USD = '20.00';

    public function __construct(
        private readonly CarRentalAvailabilityService $availability,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
        private readonly DocumentNumberService $documentNumbers,
        private readonly AuditLogger $audit,
    ) {
    }

    public function availability(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'pickup_at' => ['required', 'date'],
            'due_at' => ['required', 'date'],
            'category' => ['nullable', Rule::in(CarRentalVehicle::CATEGORIES)],
        ]);

        $site = $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        [$pickupAt, $dueAt] = $this->interval($company, $data['pickup_at'], $data['due_at']);

        $vehicles = $this->availability->availableVehicles(
            $company->id,
            $site->id,
            $pickupAt,
            $dueAt,
            $data['category'] ?? null,
        );

        return response()->json([
            'data' => $vehicles->map(fn (CarRentalVehicle $vehicle): array => $this->vehiclePayload($vehicle)),
            'period' => [
                'pickup_at' => $pickupAt->toIso8601String(),
                'due_at' => $dueAt->toIso8601String(),
                'timezone' => $company->timezone,
                'timezone_label' => $company->timezone_display_name,
            ],
        ]);
    }

    public function vehicles(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'site_id' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(CarRentalVehicle::CATEGORIES)],
            'operational_status' => ['nullable', Rule::in(CarRentalVehicle::OPERATIONAL_STATUSES)],
            'active' => ['nullable', 'boolean'],
        ]);

        $siteIds = ($data['site_id'] ?? null) === null
            ? $this->siteAuthorizer->activeSiteIdsFor($company, $access)
            : collect([$this->siteAuthorizer->siteFor($company, $access, $data['site_id'])->id]);

        $vehicles = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->when($data['category'] ?? null, static fn ($query, string $category) => $query->where('category', $category))
            ->when(
                array_key_exists('operational_status', $data),
                static fn ($query) => $query->where('operational_status', $data['operational_status']),
            )
            ->when(
                array_key_exists('active', $data),
                static fn ($query) => $query->where('is_active', $data['active']),
            )
            ->with(['site', 'documents'])
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $vehicles->map(fn (CarRentalVehicle $vehicle): array => $this->vehiclePayload($vehicle, true, $company)),
        ]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $request->merge([
            'registration_number' => $this->canonicalVehicleIdentifier($request->input('registration_number')),
            'vin' => $this->canonicalVehicleIdentifier($request->input('vin')),
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
            'vin' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('car_rental_vehicles', 'vin')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'latest_odometer_km' => ['required', 'integer', 'min:0'],
        ], $this->vehicleValidationMessages());

        $site = $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        $vehicle = CarRentalVehicle::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $data['registration_number'],
            'category' => $data['category'],
            'operational_status' => $data['operational_status'] ?? 'available',
            'make' => $this->nullableTrimmed($data['make'] ?? null),
            'model' => $this->nullableTrimmed($data['model'] ?? null),
            'model_year' => $data['model_year'] ?? null,
            'registration_number' => $data['registration_number'],
            'registration_status' => $data['registration_status'],
            'vin' => $this->nullableTrimmed($data['vin'] ?? null),
            'latest_odometer_km' => $data['latest_odometer_km'],
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
            ],
        );

        return response()->json([
            'data' => $this->vehiclePayload($vehicle->load(['site', 'documents']), true, $company),
        ], 201);
    }

    public function updateVehicleStatus(Request $request, string $vehicle): JsonResponse
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
            'data' => $this->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }

    public function updateVehicleRegistration(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $request->merge([
            'registration_number' => $this->canonicalVehicleIdentifier($request->input('registration_number')),
        ]);

        $model = $this->vehicleFor($company, $access, $vehicle);
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
        ], $this->vehicleValidationMessages());

        $previousNumber = (string) ($model->registration_number ?: $model->code);
        $previousStatus = $model->registration_status ?: 'official';

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
            'data' => $this->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }

    public function vehicleDocuments(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $model = $this->vehicleFor($company, $access, $vehicle);

        $documents = CarRentalVehicleDocument::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $model->id)
            ->orderBy('document_type')
            ->get();

        return response()->json([
            'data' => $documents
                ->map(fn (CarRentalVehicleDocument $document): array => $this->vehicleDocumentPayload($document, $company))
                ->values(),
        ]);
    }

    public function saveVehicleDocuments(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->vehicleFor($company, $access, $vehicle);

        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:3'],
            'documents.*.type' => ['required', 'distinct', Rule::in(CarRentalVehicleDocument::TYPES)],
            'documents.*.document_number' => ['nullable', 'string', 'max:100'],
            'documents.*.issued_at' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.expires_at' => ['nullable', 'date_format:Y-m-d'],
        ], $this->vehicleDocumentValidationMessages());

        foreach ($data['documents'] as $document) {
            if (in_array($document['type'], ['oavct_insurance', 'tint_permit'], true)
                && empty($document['expires_at'])) {
                throw ValidationException::withMessages([
                    'documents' => $document['type'] === 'oavct_insurance'
                        ? 'Indiquez la date d’expiration de l’assurance OAVCT.'
                        : 'Indiquez la date d’expiration du permis de vitres teintées.',
                ]);
            }
        }

        DB::transaction(function () use ($company, $model, $data): void {
            foreach ($data['documents'] as $document) {
                CarRentalVehicleDocument::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'vehicle_id' => $model->id,
                        'document_type' => $document['type'],
                    ],
                    [
                        'document_number' => $this->nullableTrimmed($document['document_number'] ?? null),
                        'issued_at' => $document['issued_at'] ?? null,
                        'expires_at' => $document['expires_at'] ?? null,
                    ],
                );
            }
        });

        $this->audit->record(
            eventType: 'car_rental.vehicle_documents_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalVehicle::class,
            subjectId: $model->id,
            metadata: [
                'site_id' => $model->site_id,
                'document_types' => collect($data['documents'])->pluck('type')->sort()->values()->all(),
            ],
        );

        $documents = CarRentalVehicleDocument::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $model->id)
            ->orderBy('document_type')
            ->get();

        return response()->json([
            'data' => $documents
                ->map(fn (CarRentalVehicleDocument $document): array => $this->vehicleDocumentPayload($document, $company))
                ->values(),
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'site_id' => ['nullable', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        [$from, $to] = $this->interval($company, $data['from'], $data['to']);
        $siteIds = ($data['site_id'] ?? null) === null
            ? $this->siteAuthorizer->activeSiteIdsFor($company, $access)
            : collect([$this->siteAuthorizer->siteFor($company, $access, $data['site_id'])->id]);

        $reservations = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->whereIn('state', CarRentalReservation::ACTIVE_STATES)
            ->where('pickup_at', '<', $to)
            ->where('due_at', '>', $from)
            ->with(['site', 'vehicle'])
            ->orderBy('pickup_at')
            ->get();

        $vehicles = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->with('site')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $reservations->map(fn (CarRentalReservation $reservation): array => $this->calendarPayload($reservation)),
            'vehicles' => $vehicles->map(fn (CarRentalVehicle $vehicle): array => $this->vehiclePayload($vehicle)),
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'timezone' => $company->timezone,
                'timezone_label' => $company->timezone_display_name,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(CarRentalVehicle::CATEGORIES)],
            'customer_profile_id' => ['nullable', 'uuid'],
            'customer' => ['required_without:customer_profile_id', 'array'],
            'customer.customer_type' => ['nullable', Rule::in(['individual', 'institution'])],
            'customer.display_name' => ['required_without:customer_profile_id', 'string', 'max:160'],
            'customer.email' => ['nullable', 'email:rfc', 'max:254'],
            'customer.phone' => ['nullable', 'string', 'max:64'],
            'customer.group_contact_sharing_consent' => ['nullable', 'boolean'],
            'pickup_at' => ['required', 'date'],
            'due_at' => ['required', 'date'],
            'pickup_location_type' => ['required', Rule::in(CarRentalReservation::LOCATION_TYPES)],
            'pickup_location_detail' => ['nullable', 'string', 'max:1000', 'required_if:pickup_location_type,custom'],
            'dropoff_location_type' => ['required', Rule::in(CarRentalReservation::LOCATION_TYPES)],
            'dropoff_location_detail' => ['nullable', 'string', 'max:1000', 'required_if:dropoff_location_type,custom'],
            'apply_airport_pickup_fee' => ['nullable', 'boolean'],
            'apply_airport_dropoff_fee' => ['nullable', 'boolean'],
            'currency' => ['required', Rule::in(['HTG', 'USD'])],
            'daily_rate' => ['required', 'numeric', 'min:0'],
            'kilometer_plan' => ['required', Rule::in(CarRentalReservation::KILOMETER_PLANS)],
            'included_km' => ['nullable', 'integer', 'min:0', 'required_if:kilometer_plan,limited'],
            'additional_km_rate' => ['nullable', 'numeric', 'min:0', 'required_if:kilometer_plan,limited'],
        ]);

        $site = $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        [$pickupAt, $dueAt] = $this->interval($company, $data['pickup_at'], $data['due_at']);
        $airportPickupFee = $this->airportServiceFee(
            $data['pickup_location_type'],
            (bool) ($data['apply_airport_pickup_fee'] ?? false),
            'apply_airport_pickup_fee',
        );
        $airportDropoffFee = $this->airportServiceFee(
            $data['dropoff_location_type'],
            (bool) ($data['apply_airport_dropoff_fee'] ?? false),
            'apply_airport_dropoff_fee',
        );

        $reservation = DB::transaction(function () use ($company, $site, $data, $pickupAt, $dueAt, $airportPickupFee, $airportDropoffFee): CarRentalReservation {
            $vehicle = $this->availability->reserveVehicle(
                $company->id,
                $site->id,
                $pickupAt,
                $dueAt,
                $data['vehicle_id'] ?? null,
                $data['category'] ?? null,
            );

            $customer = $this->resolveCustomer($company, $data);
            $number = $this->documentNumbers->next($company->id, 'car_rental_reservation');

            return CarRentalReservation::query()->create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'customer_profile_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'reservation_number' => $number,
                'state' => 'reserved',
                'pickup_at' => $pickupAt,
                'due_at' => $dueAt,
                'pickup_location_type' => $data['pickup_location_type'],
                'pickup_location_detail' => $this->locationDetail($data['pickup_location_type'], $data['pickup_location_detail'] ?? null, $site),
                'dropoff_location_type' => $data['dropoff_location_type'],
                'dropoff_location_detail' => $this->locationDetail($data['dropoff_location_type'], $data['dropoff_location_detail'] ?? null, $site),
                'airport_pickup_fee_usd' => $airportPickupFee,
                'airport_dropoff_fee_usd' => $airportDropoffFee,
                'currency' => $data['currency'],
                'daily_rate' => $data['daily_rate'],
                'kilometer_plan' => $data['kilometer_plan'],
                'included_km' => $data['kilometer_plan'] === 'limited' ? $data['included_km'] : null,
                'additional_km_rate' => $data['kilometer_plan'] === 'limited' ? $data['additional_km_rate'] : null,
            ]);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_created',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $reservation->id,
            metadata: [
                'reservation_number' => $reservation->formattedNumber(),
                'state' => $reservation->state,
                'site_id' => $reservation->site_id,
                'vehicle_id' => $reservation->vehicle_id,
                'currency' => $reservation->currency,
                'airport_pickup_service' => $airportPickupFee !== '0.00',
                'airport_dropoff_service' => $airportDropoffFee !== '0.00',
            ],
        );

        return response()->json([
            'data' => $this->reservationPayload($reservation->load(['vehicle', 'customerProfile'])),
        ], 201);
    }

    public function show(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $model = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation)
            ->with(['vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections.photos'])
            ->first();

        abort_if($model === null, 404, 'Réservation introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        return response()->json([
            'data' => $this->reservationPayload($model),
        ]);
    }

    public function submitPayment(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $model = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation)
            ->first();

        abort_if($model === null, 404, 'Réservation introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        $data = $request->validate([
            'payment_kind' => ['required', Rule::in(CarRentalPayment::KINDS)],
            'method' => ['required', Rule::in(CarRentalPayment::METHODS)],
            'currency' => ['required', Rule::in(['HTG', 'USD'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_register_id' => ['nullable', 'uuid', 'required_if:method,cash'],
            'bank_name' => ['nullable', 'string', 'max:64', 'required_if:method,bank_transfer'],
            'bank_reference' => ['nullable', 'string', 'max:128', 'required_if:method,bank_transfer'],
            'proof_storage_key' => ['nullable', 'string', 'max:512', 'required_if:method,bank_transfer'],
            'proof_sha256' => ['nullable', 'regex:/\\A[a-fA-F0-9]{64}\\z/', 'required_if:method,bank_transfer'],
        ]);

        if ($data['method'] === 'bank_transfer' && strcasecmp((string) $data['bank_name'], 'Sogebank') !== 0) {
            throw ValidationException::withMessages([
                'bank_name' => 'Les virements Car Rental doivent être déposés à la Sogebank.',
            ]);
        }

        $payment = DB::transaction(function () use ($company, $model, $data): CarRentalPayment {
            $cashRegisterId = $data['cash_register_id'] ?? null;

            if ($cashRegisterId !== null) {
                $registerExists = CashRegister::query()
                    ->where('company_id', $company->id)
                    ->where('site_id', $model->site_id)
                    ->whereKey($cashRegisterId)
                    ->where('is_active', true)
                    ->exists();

                if (! $registerExists) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'Cette caisse n’est pas active pour l’adresse de la réservation.',
                    ]);
                }
            }

            $payment = new CarRentalPayment([
                'company_id' => $company->id,
                'reservation_id' => $model->id,
                'cash_register_id' => $cashRegisterId,
                'payment_kind' => $data['payment_kind'],
                'method' => $data['method'],
                'status' => 'submitted',
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'bank_name' => $data['method'] === 'bank_transfer' ? 'Sogebank' : null,
                'proof_storage_key' => $data['proof_storage_key'] ?? null,
                'proof_sha256' => $data['proof_sha256'] ?? null,
            ]);
            $payment->setBankReference($data['bank_reference'] ?? null);
            $payment->save();

            return $payment;
        });

        $this->audit->record(
            eventType: 'car_rental.payment_submitted',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalPayment::class,
            subjectId: $payment->id,
            metadata: [
                'reservation_id' => $model->id,
                'method' => $payment->method,
                'kind' => $payment->payment_kind,
                'status' => $payment->status,
                'currency' => $payment->currency,
            ],
        );

        return response()->json([
            'data' => $this->paymentPayload($payment),
        ], 201);
    }

    public function approvePayment(Request $request, string $reservation, string $payment): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $model = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation)
            ->first();

        abort_if($model === null, 404, 'Réservation introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        $record = DB::transaction(function () use ($company, $model, $payment, $actor): CarRentalPayment {
            $record = CarRentalPayment::query()
                ->where('company_id', $company->id)
                ->where('reservation_id', $model->id)
                ->whereKey($payment)
                ->lockForUpdate()
                ->first();

            abort_if($record === null, 404, 'Paiement introuvable.');

            if ($record->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'payment' => 'Seul un paiement soumis peut être approuvé.',
                ]);
            }

            $record->forceFill([
                'status' => 'approved',
                'approved_by' => $actor?->id,
                'approved_at' => now()->utc(),
            ])->save();

            return $record;
        });

        $this->audit->record(
            eventType: 'car_rental.payment_approved',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalPayment::class,
            subjectId: $record->id,
            metadata: [
                'reservation_id' => $model->id,
                'method' => $record->method,
                'kind' => $record->payment_kind,
                'currency' => $record->currency,
            ],
        );

        return response()->json([
            'data' => $this->paymentPayload($record),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function resolveCustomer(Company $company, array $data): CustomerProfile
    {
        $profileId = $data['customer_profile_id'] ?? null;

        if ($profileId !== null) {
            $profile = CustomerProfile::query()
                ->where('company_id', $company->id)
                ->whereKey($profileId)
                ->where('is_active', true)
                ->first();

            if ($profile === null) {
                throw ValidationException::withMessages([
                    'customer_profile_id' => 'Client introuvable pour cette société.',
                ]);
            }

            return $profile;
        }

        /** @var array<string, mixed> $customer */
        $customer = $data['customer'];
        $consent = (bool) ($customer['group_contact_sharing_consent'] ?? false);
        $identity = CustomerIdentity::query()->create();
        $profile = new CustomerProfile([
            'company_id' => $company->id,
            'customer_identity_id' => $identity->id,
            'customer_type' => $customer['customer_type'] ?? 'individual',
            'display_name' => $customer['display_name'],
            'group_contact_sharing_consent' => $consent,
            'group_contact_sharing_consented_at' => $consent ? now()->utc() : null,
            'is_active' => true,
        ]);
        $profile->assignContact($customer['email'] ?? null, $customer['phone'] ?? null);
        $profile->save();

        return $profile;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function interval(Company $company, string $pickupAt, string $dueAt): array
    {
        $pickup = CarbonImmutable::parse($pickupAt, $company->timezone)->utc();
        $due = CarbonImmutable::parse($dueAt, $company->timezone)->utc();

        if ($due->lessThanOrEqualTo($pickup)) {
            throw ValidationException::withMessages([
                'due_at' => 'La date de fin doit être postérieure à la date de début.',
            ]);
        }

        return [$pickup, $due];
    }

    private function locationDetail(string $type, ?string $detail, Site $site): ?string
    {
        return match ($type) {
            'cap_haitien_airport' => 'Aéroport International du Cap-Haïtien',
            'site' => trim($site->name . ' · ' . $site->address),
            default => $detail,
        };
    }

    private function airportServiceFee(string $locationType, bool $apply, string $field): string
    {
        if (! $apply) {
            return '0.00';
        }

        if ($locationType !== 'cap_haitien_airport') {
            throw ValidationException::withMessages([
                $field => 'Le frais aéroport s’applique uniquement lorsque le lieu est l’Aéroport International du Cap-Haïtien.',
            ]);
        }

        return self::AIRPORT_SERVICE_FEE_USD;
    }

    private function company(Request $request): Company
    {
        $company = $request->attributes->get('clientele.company');
        abort_unless($company instanceof Company, 500, 'Contexte de société manquant.');

        return $company;
    }

    private function access(Request $request): CompanyUserAccess
    {
        $access = $request->attributes->get('clientele.company_access');
        abort_unless($access instanceof CompanyUserAccess, 500, 'Contexte d’accès manquant.');

        return $access;
    }

    /** @return array<string, mixed> */
    private function vehiclePayload(
        CarRentalVehicle $vehicle,
        bool $includeManagementDetails = false,
        ?Company $company = null,
    ): array
    {
        $payload = [
            'id' => $vehicle->id,
            'site_id' => $vehicle->site_id,
            'site' => $vehicle->relationLoaded('site') && $vehicle->site !== null ? [
                'id' => $vehicle->site->id,
                'code' => $vehicle->site->code,
                'name' => $vehicle->site->name,
            ] : null,
            'code' => $vehicle->code,
            'category' => $vehicle->category,
            'operational_status' => $vehicle->operational_status,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'model_year' => $vehicle->model_year,
            'latest_odometer_km' => $vehicle->latest_odometer_km,
            'is_active' => $vehicle->is_active,
        ];

        if ($includeManagementDetails) {
            $payload['registration_number'] = $vehicle->registration_number ?: $vehicle->code;
            $payload['registration_status'] = $vehicle->registration_status ?: 'official';
            $payload['document_statuses'] = $company !== null
                ? $this->vehicleDocumentStatuses($vehicle, $company)
                : [];
        }

        return $payload;
    }

    private function vehicleFor(Company $company, CompanyUserAccess $access, string $vehicle): CarRentalVehicle
    {
        $model = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($vehicle)
            ->first();

        abort_if($model === null, 404, 'Véhicule introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        return $model;
    }

    /** @return array<int, array<string, mixed>> */
    private function vehicleDocumentStatuses(CarRentalVehicle $vehicle, Company $company): array
    {
        $documents = $vehicle->relationLoaded('documents')
            ? $vehicle->documents->keyBy('document_type')
            : collect();

        return collect(CarRentalVehicleDocument::TYPES)
            ->map(function (string $type) use ($documents, $company): array {
                /** @var CarRentalVehicleDocument|null $document */
                $document = $documents->get($type);

                return [
                    'type' => $type,
                    'status' => $this->vehicleDocumentStatus($document, $company),
                    'expires_at' => $document?->expires_at?->format('Y-m-d'),
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function vehicleDocumentPayload(CarRentalVehicleDocument $document, Company $company): array
    {
        return [
            'id' => $document->id,
            'type' => $document->document_type,
            'document_number' => $document->document_number,
            'issued_at' => $document->issued_at?->format('Y-m-d'),
            'expires_at' => $document->expires_at?->format('Y-m-d'),
            'status' => $this->vehicleDocumentStatus($document, $company),
        ];
    }

    private function vehicleDocumentStatus(?CarRentalVehicleDocument $document, Company $company): string
    {
        if ($document === null || $document->expires_at === null) {
            return $document === null ? 'not_recorded' : 'not_applicable';
        }

        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $expiration = CarbonImmutable::instance($document->expires_at)->startOfDay();

        if ($expiration->lessThan($today)) {
            return 'expired';
        }

        if ($expiration->lessThanOrEqualTo($today->addDays(30))) {
            return 'expiring_soon';
        }

        return 'current';
    }

    /** @return array<string, string> */
    private function vehicleValidationMessages(): array
    {
        return [
            'site_id.required' => 'Sélectionnez une adresse.',
            'site_id.uuid' => 'Sélectionnez une adresse valide.',
            'category.required' => 'Sélectionnez une catégorie.',
            'category.in' => 'Sélectionnez une catégorie valide.',
            'operational_status.in' => 'Sélectionnez un état opérationnel valide.',
            'registration_number.required' => 'Saisissez la plaque d’immatriculation en cours.',
            'registration_number.max' => 'La plaque ne peut pas dépasser 32 caractères.',
            'registration_number.unique' => 'Cette plaque est déjà utilisée par un autre véhicule de cette société.',
            'registration_status.required' => 'Sélectionnez le type de plaque.',
            'registration_status.in' => 'Sélectionnez « Démonstration » ou « Officielle ».',
            'vin.max' => 'Le VIN ne peut pas dépasser 64 caractères.',
            'vin.unique' => 'Ce VIN est déjà utilisé par un autre véhicule de cette société.',
            'latest_odometer_km.required' => 'Saisissez le kilométrage actuel.',
            'latest_odometer_km.integer' => 'Le kilométrage doit être un nombre entier.',
            'latest_odometer_km.min' => 'Le kilométrage ne peut pas être négatif.',
        ];
    }

    /** @return array<string, string> */
    private function vehicleDocumentValidationMessages(): array
    {
        return [
            'documents.required' => 'Ajoutez au moins un document à enregistrer.',
            'documents.array' => 'Les documents doivent être fournis dans un format valide.',
            'documents.min' => 'Ajoutez au moins un document à enregistrer.',
            'documents.max' => 'Vous pouvez enregistrer au plus trois documents à la fois.',
            'documents.*.type.required' => 'Sélectionnez le type de document.',
            'documents.*.type.distinct' => 'Chaque type de document ne peut être saisi qu’une fois.',
            'documents.*.type.in' => 'Sélectionnez un type de document valide.',
            'documents.*.document_number.max' => 'La référence ne peut pas dépasser 100 caractères.',
            'documents.*.issued_at.date_format' => 'Utilisez une date de délivrance valide.',
            'documents.*.expires_at.date_format' => 'Utilisez une date d’expiration valide.',
        ];
    }

    /** @return array<string, mixed> */
    private function calendarPayload(CarRentalReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'number' => $reservation->formattedNumber(),
            'state' => $reservation->state,
            'pickup_at' => $reservation->pickup_at?->toIso8601String(),
            'due_at' => $reservation->due_at?->toIso8601String(),
            'site' => $reservation->site === null ? null : [
                'id' => $reservation->site->id,
                'code' => $reservation->site->code,
                'name' => $reservation->site->name,
            ],
            'vehicle' => $reservation->vehicle === null ? null : $this->vehiclePayload($reservation->vehicle),
        ];
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function canonicalVehicleIdentifier(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $identifier = strtoupper(trim($value));

        return $identifier === '' ? null : $identifier;
    }

    /** @return array<string, mixed> */
    private function reservationPayload(CarRentalReservation $reservation): array
    {
        $airportPickupFee = (float) $reservation->airport_pickup_fee_usd;
        $airportDropoffFee = (float) $reservation->airport_dropoff_fee_usd;

        return [
            'id' => $reservation->id,
            'number' => $reservation->formattedNumber(),
            'state' => $reservation->state,
            'site_id' => $reservation->site_id,
            'pickup_at' => $reservation->pickup_at?->toIso8601String(),
            'due_at' => $reservation->due_at?->toIso8601String(),
            'pickup_location' => [
                'type' => $reservation->pickup_location_type,
                'detail' => $reservation->pickup_location_detail,
            ],
            'dropoff_location' => [
                'type' => $reservation->dropoff_location_type,
                'detail' => $reservation->dropoff_location_detail,
            ],
            'airport_pickup_fee_usd' => number_format($airportPickupFee, 2, '.', ''),
            'airport_dropoff_fee_usd' => number_format($airportDropoffFee, 2, '.', ''),
            'airport_fees_total_usd' => number_format($airportPickupFee + $airportDropoffFee, 2, '.', ''),
            'currency' => $reservation->currency,
            'daily_rate' => $reservation->daily_rate,
            'kilometer_plan' => $reservation->kilometer_plan,
            'included_km' => $reservation->included_km,
            'additional_km_rate' => $reservation->additional_km_rate,
            'vehicle' => $reservation->vehicle === null ? null : $this->vehiclePayload($reservation->vehicle),
            'customer' => $reservation->customerProfile === null ? null : [
                'id' => $reservation->customerProfile->id,
                'display_name' => $reservation->customerProfile->display_name,
                'customer_type' => $reservation->customerProfile->customer_type,
            ],
            'payments' => $reservation->relationLoaded('payments')
                ? $reservation->payments->map(fn (CarRentalPayment $payment): array => $this->paymentPayload($payment))
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentPayload(CarRentalPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'kind' => $payment->payment_kind,
            'method' => $payment->method,
            'status' => $payment->status,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'submitted_at' => $payment->submitted_at?->toIso8601String(),
            'approved_at' => $payment->approved_at?->toIso8601String(),
        ];
    }
}
