<?php

namespace App\Http\Controllers;

use App\Models\CarRentalInspection;
use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CarRentalSecurityDeposit;
use App\Models\CarRentalVehicle;
use App\Models\CarRentalVehicleDocument;
use App\Models\CarRentalVehicleRegistrationEvent;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CustomerIdentity;
use App\Models\CustomerProfile;
use App\Models\Site;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\CarRentalAvailabilityService;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CompanySiteAuthorizer;
use App\Support\DocumentNumberService;
use App\Support\FileVault;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CarRentalController extends Controller
{
    private const AIRPORT_SERVICE_FEE_USD = '20.00';

    /**
     * Pays dont les permis sont délivrés par un État, une province ou un
     * territoire. Ailleurs (Haïti, France, etc.), le permis est national.
     */
    private const LICENSE_SUBDIVISION_COUNTRIES = ['US', 'CA', 'MX', 'AU', 'BR', 'IN'];

    public function __construct(
        private readonly CarRentalAvailabilityService $availability,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
        private readonly DocumentNumberService $documentNumbers,
        private readonly AuditLogger $audit,
        private readonly FileVault $files,
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
            ...$this->vehicleContractRules(),
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
            'reference_photo_key' => $data['reference_photo_key'] ?? null,
            'vin' => $this->nullableTrimmed($data['vin'] ?? null),
            'latest_odometer_km' => $data['latest_odometer_km'],
            'daily_rate_usd' => $data['daily_rate_usd'],
            'minimum_security_deposit_usd' => $data['minimum_security_deposit_usd'],
            ...$this->vehicleContractAttributes($data),
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
            'data' => $this->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }

    public function updateVehicleCommercialTerms(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->vehicleFor($company, $access, $vehicle);

        $data = $request->validate([
            'daily_rate_usd' => ['required', 'numeric', 'gt:0'],
            'minimum_security_deposit_usd' => ['required', 'numeric', 'gte:0'],
        ], $this->vehicleValidationMessages());

        $changed = (string) $model->daily_rate_usd !== (string) $data['daily_rate_usd']
            || (string) $model->minimum_security_deposit_usd !== (string) $data['minimum_security_deposit_usd'];

        $model->forceFill([
            'daily_rate_usd' => $data['daily_rate_usd'],
            'minimum_security_deposit_usd' => $data['minimum_security_deposit_usd'],
        ])->save();

        if ($changed) {
            $this->audit->record(
                eventType: 'car_rental.vehicle_commercial_terms_updated',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: CarRentalVehicle::class,
                subjectId: $model->id,
                metadata: [
                    'site_id' => $model->site_id,
                    'daily_rate_usd' => $model->daily_rate_usd,
                    'minimum_security_deposit_usd' => $model->minimum_security_deposit_usd,
                ],
            );
        }

        return response()->json([
            'data' => $this->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }

    /** Identité du véhicule imprimée sur le contrat : marque, modèle, couleur, motorisation. */
    public function updateVehicleDetails(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->vehicleFor($company, $access, $vehicle);

        // Le numéro de série n'est modifié que s'il est envoyé.
        $vinProvided = $request->exists('vin');
        $request->merge(['vin' => $this->canonicalVehicleIdentifier($request->input('vin'))]);
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
            ...$this->vehicleContractRules(),
        ], $this->vehicleValidationMessages());

        $model->forceFill([
            'category' => $data['category'],
            'make' => $this->nullableTrimmed($data['make'] ?? null),
            'model' => $this->nullableTrimmed($data['model'] ?? null),
            'model_year' => $data['model_year'] ?? null,
            'vin' => $vinProvided ? $this->nullableTrimmed($data['vin'] ?? null) : $model->vin,
            'latest_odometer_km' => $data['latest_odometer_km'],
            ...$this->vehicleContractAttributes($data),
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
            'data' => $this->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }

    /**
     * Met un véhicule hors flotte ou l'y remet. Un véhicule qui a une
     * réservation à venir ou une location en cours ne peut pas être désactivé.
     */
    public function updateVehicleActive(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->vehicleFor($company, $access, $vehicle);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $active = (bool) $data['is_active'];

        if (! $active) {
            $open = CarRentalReservation::query()
                ->where('company_id', $company->id)
                ->where('vehicle_id', $model->id)
                ->whereIn('state', ['reserved', 'checked_out'])
                ->count();

            if ($open > 0) {
                throw ValidationException::withMessages([
                    'is_active' => $open > 1
                        ? "Ce véhicule a {$open} réservations ou locations en cours. Modifiez-les avant de le désactiver."
                        : 'Ce véhicule a une réservation ou une location en cours. Modifiez-la avant de le désactiver.',
                ]);
            }
        }

        if ($model->is_active !== $active) {
            $model->forceFill(['is_active' => $active])->save();
            $this->audit->record(
                eventType: $active ? 'car_rental.vehicle_activated' : 'car_rental.vehicle_deactivated',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: CarRentalVehicle::class,
                subjectId: $model->id,
                metadata: ['site_id' => $model->site_id],
            );
        }

        return response()->json([
            'data' => $this->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }

    /** Associe une photo réelle, déjà téléversée, à la fiche véhicule. */
    public function updateVehiclePhoto(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->vehicleFor($company, $access, $vehicle);

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
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from'],
        ]);

        [$from, $to] = isset($data['from'], $data['to'])
            ? $this->calendarInterval($company, $data['from'], $data['to'])
            : $this->defaultCalendarInterval($company);
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

    /**
     * Liste opérationnelle limitée au périmètre autorisé. La recherche ne
     * porte pas sur les coordonnées des clients et la réponse ne les expose
     * jamais.
     */
    public function reservations(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'site_id' => ['nullable', 'uuid'],
            'state' => ['nullable', Rule::in(CarRentalReservation::STATES)],
            'query' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from'],
        ]);

        [$from, $to] = isset($data['from'], $data['to'])
            ? $this->calendarInterval($company, $data['from'], $data['to'])
            : $this->defaultCalendarInterval($company);
        $siteIds = ($data['site_id'] ?? null) === null
            ? $this->siteAuthorizer->activeSiteIdsFor($company, $access)
            : collect([$this->siteAuthorizer->siteFor($company, $access, $data['site_id'])->id]);
        $search = $this->reservationSearchTerm($data['query'] ?? null);

        $reservations = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->when($data['state'] ?? null, static fn ($query, string $state) => $query->where('state', $state))
            ->where('pickup_at', '<', $to)
            ->where('due_at', '>', $from)
            ->when($search !== null, function ($query) use ($search): void {
                $like = '%' . $search . '%';

                $query->where(function ($searchQuery) use ($like): void {
                    $searchQuery
                        ->whereRaw('UPPER(reservation_number) LIKE ?', [$like])
                        ->orWhereHas('vehicle', static function ($vehicleQuery) use ($like): void {
                            $vehicleQuery->whereRaw('UPPER(code) LIKE ?', [$like]);
                        });
                });
            })
            ->with(['site', 'vehicle', 'customerProfile'])
            ->orderByDesc('pickup_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $reservations
                ->map(fn (CarRentalReservation $reservation): array => $this->reservationListPayload($reservation))
                ->values(),
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
            // Le contrat papier laisse ce prix à compléter : il reste facultatif.
            'additional_km_rate' => ['nullable', 'numeric', 'min:0'],
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

        $reservation = DB::transaction(function () use ($company, $access, $site, $data, $pickupAt, $dueAt, $airportPickupFee, $airportDropoffFee): CarRentalReservation {
            $vehicle = $this->availability->reserveVehicle(
                $company->id,
                $site->id,
                $pickupAt,
                $dueAt,
                $data['vehicle_id'] ?? null,
                $data['category'] ?? null,
            );
            $this->assertVehicleCommercialTermsConfigured($vehicle);
            $rateOverridden = $this->assertRateAllowed($access, $vehicle, $data['currency'], (string) $data['daily_rate']);

            $customer = $this->resolveCustomer($company, $data);
            $number = $this->documentNumbers->next($company->id, 'car_rental_reservation');

            return CarRentalReservation::query()->create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'customer_profile_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'reservation_number' => $number,
                'state' => 'reserved',
                'lock_version' => 0,
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
                'rate_overridden' => $rateOverridden,
                'minimum_security_deposit_usd' => $vehicle->minimum_security_deposit_usd,
                'kilometer_plan' => $data['kilometer_plan'],
                'included_km' => $data['kilometer_plan'] === 'limited' ? $data['included_km'] : null,
                'additional_km_rate' => $data['kilometer_plan'] === 'limited' ? ($data['additional_km_rate'] ?? null) : null,
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
                'rate_overridden' => $reservation->rate_overridden,
                'airport_pickup_service' => $airportPickupFee !== '0.00',
                'airport_dropoff_service' => $airportDropoffFee !== '0.00',
            ],
        );

        $customerNotificationSent = $this->customerNotifications->notify(
            $company,
            $reservation,
            CarRentalCustomerNotificationService::RESERVATION_CREATED,
        );

        return response()->json([
            'data' => $this->reservationPayload($reservation->load(['vehicle', 'customerProfile']), $access),
            'customer_notification_sent' => $customerNotificationSent,
        ], 201);
    }

    public function show(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $model = $this->reservationFor(
            $company,
            $access,
            $reservation,
            ['vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections.photos'],
        );

        return response()->json([
            'data' => $this->reservationPayload($model, $access),
        ]);
    }

    /**
     * Modifie une réservation qui n'a pas encore été remise : client,
     * coordonnées, véhicule (toute catégorie), dates, lieux et conditions.
     *
     * Le tarif suit la fiche du véhicule. Un changement de véhicule applique
     * le tarif et le dépôt minimum du nouveau véhicule. Seul un rôle autorisé
     * peut fixer un autre tarif. Les paiements existants ne sont jamais
     * modifiés par cette action.
     */
    public function updateReservation(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'pickup_at' => ['required', 'date'],
            'due_at' => ['required', 'date'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'customer' => ['sometimes', 'array'],
            'customer.customer_type' => ['sometimes', Rule::in(['individual', 'institution'])],
            'customer.display_name' => ['required_with:customer', 'string', 'max:160'],
            'customer.email' => ['nullable', 'email:rfc', 'max:254'],
            'customer.phone' => ['nullable', 'string', 'max:64'],
            'pickup_location_type' => ['sometimes', Rule::in(CarRentalReservation::LOCATION_TYPES)],
            'pickup_location_detail' => ['nullable', 'string', 'max:1000', 'required_if:pickup_location_type,custom'],
            'dropoff_location_type' => ['sometimes', Rule::in(CarRentalReservation::LOCATION_TYPES)],
            'dropoff_location_detail' => ['nullable', 'string', 'max:1000', 'required_if:dropoff_location_type,custom'],
            'apply_airport_pickup_fee' => ['nullable', 'boolean'],
            'apply_airport_dropoff_fee' => ['nullable', 'boolean'],
            'currency' => ['sometimes', Rule::in(['HTG', 'USD'])],
            'daily_rate' => ['sometimes', 'numeric', 'gt:0'],
            'kilometer_plan' => ['sometimes', Rule::in(CarRentalReservation::KILOMETER_PLANS)],
            'included_km' => ['nullable', 'integer', 'min:0'],
            'additional_km_rate' => ['nullable', 'numeric', 'min:0'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);
        [$pickupAt, $dueAt] = $this->interval($company, $data['pickup_at'], $data['due_at']);

        /** @var array<int, string> $changes */
        $changes = [];

        $model = DB::transaction(function () use ($company, $access, $reservation, $data, $pickupAt, $dueAt, &$changes): CarRentalReservation {
            $model = $this->reservationFor($company, $access, $reservation, ['site', 'customerProfile'], true);

            if ($model->state !== 'reserved') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une réservation non remise peut être modifiée.',
                ]);
            }

            $this->assertLockVersion($model, $data['expected_lock_version']);
            $vehicle = $this->availability->reserveVehicle(
                $company->id,
                $model->site_id,
                $pickupAt,
                $dueAt,
                $data['vehicle_id'],
                null,
                $model->id,
            );
            $vehicleChanged = $vehicle->id !== $model->vehicle_id;

            if ($vehicleChanged) {
                $this->assertVehicleCommercialTermsConfigured($vehicle);
                $changes[] = 'vehicle';
            }

            if (! $pickupAt->equalTo($model->pickup_at) || ! $dueAt->equalTo($model->due_at)) {
                $changes[] = 'schedule';
            }

            // Tarif : celui du véhicule par défaut, un autre seulement avec la permission.
            $currency = $data['currency'] ?? $model->currency;
            $dailyRate = array_key_exists('daily_rate', $data) ? (string) $data['daily_rate'] : (string) $model->daily_rate;
            $rateUnchanged = $currency === $model->currency
                && (int) round((float) $dailyRate * 100) === (int) round((float) $model->daily_rate * 100);

            if ($vehicleChanged && ! $access->allows('rental.reservations.override_rate')) {
                $currency = 'USD';
                $dailyRate = (string) $vehicle->daily_rate_usd;
                $rateOverridden = false;
            } elseif ($rateUnchanged && ! $vehicleChanged) {
                $rateOverridden = (bool) $model->rate_overridden;
            } else {
                $rateOverridden = $this->assertRateAllowed($access, $vehicle, $currency, $dailyRate);
            }

            if (! $rateUnchanged || $vehicleChanged) {
                $changes[] = 'rate';
            }

            $site = $model->site;
            $pickupType = $data['pickup_location_type'] ?? $model->pickup_location_type;
            $dropoffType = $data['dropoff_location_type'] ?? $model->dropoff_location_type;
            $attributes = [
                'vehicle_id' => $vehicle->id,
                'pickup_at' => $pickupAt,
                'due_at' => $dueAt,
                'currency' => $currency,
                'daily_rate' => $dailyRate,
                'rate_overridden' => $rateOverridden,
                'lock_version' => $model->lock_version + 1,
            ];

            if ($vehicleChanged) {
                $attributes['minimum_security_deposit_usd'] = $vehicle->minimum_security_deposit_usd;
            }

            if (array_key_exists('pickup_location_type', $data) || array_key_exists('dropoff_location_type', $data)) {
                $attributes['pickup_location_type'] = $pickupType;
                $attributes['pickup_location_detail'] = $this->locationDetail($pickupType, $data['pickup_location_detail'] ?? null, $site);
                $attributes['dropoff_location_type'] = $dropoffType;
                $attributes['dropoff_location_detail'] = $this->locationDetail($dropoffType, $data['dropoff_location_detail'] ?? null, $site);
                $attributes['airport_pickup_fee_usd'] = $this->airportServiceFee(
                    $pickupType,
                    (bool) ($data['apply_airport_pickup_fee'] ?? false),
                    'apply_airport_pickup_fee',
                );
                $attributes['airport_dropoff_fee_usd'] = $this->airportServiceFee(
                    $dropoffType,
                    (bool) ($data['apply_airport_dropoff_fee'] ?? false),
                    'apply_airport_dropoff_fee',
                );
                $changes[] = 'locations';
            }

            if (array_key_exists('kilometer_plan', $data)) {
                $limited = $data['kilometer_plan'] === 'limited';

                if ($limited && ! isset($data['included_km'])) {
                    throw ValidationException::withMessages([
                        'included_km' => 'Indiquez le nombre de kilomètres inclus.',
                    ]);
                }

                $attributes['kilometer_plan'] = $data['kilometer_plan'];
                $attributes['included_km'] = $limited ? $data['included_km'] : null;
                $attributes['additional_km_rate'] = $limited ? ($data['additional_km_rate'] ?? null) : null;
                $changes[] = 'kilometers';
            }

            if (isset($data['customer'])) {
                $profile = $model->customerProfile;
                abort_if($profile === null, 409, 'Le client de cette réservation est introuvable.');
                $profile->forceFill([
                    'customer_type' => $data['customer']['customer_type'] ?? $profile->customer_type,
                    'display_name' => trim($data['customer']['display_name']),
                ]);
                $profile->assignContact($data['customer']['email'] ?? null, $data['customer']['phone'] ?? null);

                if ($profile->isDirty()) {
                    $changes[] = 'customer';
                }

                $profile->save();
            }

            $model->forceFill($attributes)->save();

            return $model->load(['site', 'vehicle', 'customerProfile', 'payments', 'securityDeposits']);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'site_id' => $model->site_id,
                'vehicle_id' => $model->vehicle_id,
                'changed' => array_values(array_unique($changes)),
                'rate_overridden' => $model->rate_overridden,
                'payments_modified' => false,
            ],
        );

        $notificationSent = (bool) ($data['notify_customer'] ?? false)
            ? $this->customerNotifications->notify($company, $model, CarRentalCustomerNotificationService::RESERVATION_UPDATED)
            : false;

        return response()->json([
            'data' => $this->reservationPayload($model, $access),
            'customer_notification_sent' => $notificationSent,
        ]);
    }

    /** Renvoie au client la confirmation à jour de sa réservation. */
    public function notifyReservation(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $model = $this->reservationFor($company, $access, $reservation, ['site', 'vehicle', 'customerProfile']);

        if (! in_array($model->state, ['reserved', 'checked_out'], true)) {
            throw ValidationException::withMessages([
                'reservation' => 'Cette réservation est terminée ou annulée : aucune confirmation n’est renvoyée.',
            ]);
        }

        if (empty($model->customerProfile?->email)) {
            throw ValidationException::withMessages([
                'customer.email' => 'Ajoutez le courriel du client avant de renvoyer la confirmation.',
            ]);
        }

        $sent = $this->customerNotifications->notify(
            $company,
            $model,
            CarRentalCustomerNotificationService::RESERVATION_UPDATED,
        );

        return response()->json([
            'customer_notification_sent' => $sent,
            'message' => $sent
                ? 'La confirmation a été envoyée au client.'
                : 'La confirmation n’a pas pu être envoyée. Vérifiez le courriel du client et le serveur de courriel.',
        ], $sent ? 200 : 502);
    }

    public function checkOutReservation(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $request->merge([
            'driver_license_country' => is_string($request->input('driver_license_country'))
                ? strtoupper(trim($request->input('driver_license_country')))
                : $request->input('driver_license_country'),
        ]);
        $data = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'driver_full_name' => ['required', 'string', 'max:160'],
            'driver_license_number' => ['required', 'string', 'max:128'],
            'driver_license_expires_at' => ['required', 'date_format:Y-m-d'],
            'driver_license_country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'driver_license_subdivision' => [
                Rule::requiredIf(fn (): bool => in_array($request->input('driver_license_country'), self::LICENSE_SUBDIVISION_COUNTRIES, true)),
                'nullable',
                'string',
                'max:64',
            ],
            'driver_license_front_file_id' => ['required', 'uuid'],
            'driver_license_back_file_id' => ['required', 'uuid'],
            'driver_license_verified' => ['accepted'],
            'additional_driver_name' => ['nullable', 'string', 'max:160'],
            'additional_driver_license_number' => ['nullable', 'required_with:additional_driver_name', 'string', 'max:128'],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'fuel_level_percent' => ['required', 'integer', Rule::in(CarRentalInspection::FUEL_LEVELS)],
            'accessories' => ['present', 'array'],
            'accessories.*' => ['string', Rule::in(CarRentalInspection::ACCESSORIES)],
            'damage_notes' => ['nullable', 'string', 'max:2000'],
            'inspection_photo_file_ids' => ['nullable', 'array', 'max:12'],
            'inspection_photo_file_ids.*' => ['uuid'],
            'terms_accepted' => ['accepted'],
            'customer_signature_file_id' => ['required', 'uuid'],
            'company_signature_file_id' => ['required', 'uuid'],
            'company_signer_name' => ['required', 'string', 'max:160'],
        ], [
            'driver_license_country.required' => 'Choisissez le pays qui a délivré le permis.',
            'driver_license_country.regex' => 'Choisissez le pays qui a délivré le permis.',
            'driver_license_subdivision.required' => 'Indiquez l’État ou la province qui a délivré le permis.',
            'driver_license_front_file_id.required' => 'Ajoutez la photo du recto du permis.',
            'driver_license_back_file_id.required' => 'Ajoutez la photo du verso du permis.',
            'driver_license_verified.accepted' => 'Confirmez la vérification de l’original du permis.',
            'additional_driver_license_number.required_with' => 'Saisissez le numéro de permis du conducteur additionnel.',
            'odometer_km.required' => 'Saisissez le kilométrage au compteur.',
            'fuel_level_percent.required' => 'Indiquez le niveau de carburant.',
            'fuel_level_percent.in' => 'Indiquez le niveau de carburant.',
            'terms_accepted.accepted' => 'Le client doit accepter les conditions du contrat.',
            'customer_signature_file_id.required' => 'La signature du client est requise.',
            'company_signature_file_id.required' => 'La signature pour le loueur est requise.',
            'company_signer_name.required' => 'Saisissez le nom de la personne qui signe pour le loueur.',
        ]);

        if (! filled($company->rental_contract_terms)) {
            throw ValidationException::withMessages([
                'contract_terms' => 'Les conditions du contrat de location ne sont pas configurées. Le propriétaire doit les saisir dans la configuration de la société.',
            ]);
        }

        $licenseFront = $this->files->find($company, $data['driver_license_front_file_id'], StoredFile::PURPOSE_DRIVER_LICENSE_FRONT, 'driver_license_front_file_id');
        $licenseBack = $this->files->find($company, $data['driver_license_back_file_id'], StoredFile::PURPOSE_DRIVER_LICENSE_BACK, 'driver_license_back_file_id');
        $customerSignature = $this->files->find($company, $data['customer_signature_file_id'], StoredFile::PURPOSE_SIGNATURE, 'customer_signature_file_id');
        $companySignature = $this->files->find($company, $data['company_signature_file_id'], StoredFile::PURPOSE_SIGNATURE, 'company_signature_file_id');
        $inspectionPhotoIds = [];

        foreach (array_values(array_unique($data['inspection_photo_file_ids'] ?? [])) as $index => $photoId) {
            $inspectionPhotoIds[] = $this->files->find($company, $photoId, StoredFile::PURPOSE_INSPECTION_PHOTO, "inspection_photo_file_ids.{$index}")->id;
        }

        $model = DB::transaction(function () use (
            $company,
            $access,
            $reservation,
            $data,
            $actor,
            $licenseFront,
            $licenseBack,
            $customerSignature,
            $companySignature,
            $inspectionPhotoIds,
        ): CarRentalReservation {
            $model = $this->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'reserved') {
                throw ValidationException::withMessages([
                    'reservation' => 'Cette réservation ne peut pas être mise en circulation.',
                ]);
            }

            $this->assertLockVersion($model, $data['expected_lock_version']);
            $vehicle = $this->vehicleForReservation($company, $model);
            $this->availability->assertVehiclePeriodAvailable(
                $vehicle,
                CarbonImmutable::instance($model->pickup_at),
                CarbonImmutable::instance($model->due_at),
                $model->id,
            );

            $licenseExpiration = CarbonImmutable::createFromFormat('Y-m-d', $data['driver_license_expires_at'], $company->timezone)
                ->startOfDay();

            if ($licenseExpiration->lessThan(CarbonImmutable::now($company->timezone)->startOfDay())) {
                throw ValidationException::withMessages([
                    'driver_license_expires_at' => 'Le permis de conduire est expiré. Enregistrez un permis valide avant la mise en circulation.',
                ]);
            }

            $approvedRentalPayment = CarRentalPayment::query()
                ->where('company_id', $company->id)
                ->where('reservation_id', $model->id)
                ->where('payment_kind', 'rental')
                ->where('status', 'approved')
                ->lockForUpdate()
                ->first();

            if ($approvedRentalPayment === null) {
                throw ValidationException::withMessages([
                    'payment' => 'Enregistrez puis approuvez au moins un paiement de location avant la mise en circulation.',
                ]);
            }

            $minimumDeposit = $model->minimum_security_deposit_usd;

            if ($minimumDeposit === null) {
                $this->assertVehicleCommercialTermsConfigured($vehicle);
                $minimumDeposit = $vehicle->minimum_security_deposit_usd;
            }

            $minimumDepositAmount = (float) $minimumDeposit;
            $heldDeposits = CarRentalSecurityDeposit::query()
                ->where('company_id', $company->id)
                ->where('reservation_id', $model->id)
                ->where('status', 'held')
                ->where('currency', 'USD')
                ->lockForUpdate()
                ->get(['amount']);
            $heldDepositUsd = (float) $heldDeposits->sum(static fn (CarRentalSecurityDeposit $deposit): float => (float) $deposit->amount);

            if ($heldDepositUsd + 0.0001 < $minimumDepositAmount) {
                throw ValidationException::withMessages([
                    'security_deposit' => sprintf(
                        'Un dépôt de garantie approuvé de USD %.2f est requis avant la mise en circulation. Dépôt actuellement retenu : USD %.2f.',
                        $minimumDepositAmount,
                        $heldDepositUsd,
                    ),
                ]);
            }

            $odometer = (int) $data['odometer_km'];

            if ($odometer < (int) $vehicle->latest_odometer_km) {
                throw ValidationException::withMessages([
                    'odometer_km' => sprintf(
                        'Le kilométrage ne peut pas être inférieur au dernier relevé du véhicule (%s km).',
                        number_format((int) $vehicle->latest_odometer_km, 0, ',', ' '),
                    ),
                ]);
            }

            $now = now()->utc();
            $accessories = array_values(array_intersect(CarRentalInspection::ACCESSORIES, $data['accessories']));

            $model->forceFill([
                'state' => 'checked_out',
                'checked_out_at' => $now,
                'minimum_security_deposit_usd' => $minimumDeposit,
                'driver_full_name' => $this->nullableTrimmed($data['driver_full_name']),
                'driver_license_number' => $this->nullableTrimmed($data['driver_license_number']),
                'driver_license_expires_at' => $licenseExpiration,
                'driver_license_verified_at' => $now,
                'driver_license_country' => $data['driver_license_country'],
                'driver_license_subdivision' => in_array($data['driver_license_country'], self::LICENSE_SUBDIVISION_COUNTRIES, true)
                    ? $this->nullableTrimmed($data['driver_license_subdivision'] ?? null)
                    : null,
                'driver_license_front_file_id' => $licenseFront->id,
                'driver_license_back_file_id' => $licenseBack->id,
                'additional_driver_name' => $this->nullableTrimmed($data['additional_driver_name'] ?? null),
                'additional_driver_license_number' => filled($data['additional_driver_name'] ?? null)
                    ? $this->nullableTrimmed($data['additional_driver_license_number'] ?? null)
                    : null,
                'contract_snapshot' => $this->contractSnapshot($company, $vehicle),
                'lock_version' => $model->lock_version + 1,
            ])->save();

            CarRentalInspection::query()->updateOrCreate(
                ['company_id' => $company->id, 'reservation_id' => $model->id, 'stage' => 'pre_rental'],
                [
                    'vehicle_id' => $vehicle->id,
                    'inspector_user_id' => $actor?->id,
                    'status' => 'finalized',
                    'inspected_at' => $now,
                    'odometer_km' => $odometer,
                    'fuel_level_percent' => (int) $data['fuel_level_percent'],
                    'accessories' => $accessories,
                    'notes' => $this->nullableTrimmed($data['damage_notes'] ?? null),
                    'photo_file_ids' => $inspectionPhotoIds,
                    'company_signer_name' => trim($data['company_signer_name']),
                    'customer_signed_at' => $now,
                    'company_signed_at' => $now,
                    'customer_signature_file_id' => $customerSignature->id,
                    'company_signature_file_id' => $companySignature->id,
                    'customer_signature_sha256' => $customerSignature->sha256,
                    'company_signature_sha256' => $companySignature->sha256,
                ],
            );

            $vehicle->forceFill([
                'operational_status' => 'in_circulation',
                'latest_odometer_km' => $odometer,
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile', 'payments', 'securityDeposits']);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_checked_out',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'site_id' => $model->site_id,
                'vehicle_id' => $model->vehicle_id,
                'driver_license_verified' => true,
                'driver_license_country' => $model->driver_license_country,
                'driver_license_documents_sha256' => [$licenseFront->sha256, $licenseBack->sha256],
                'approved_rental_payment_verified' => true,
                'security_deposit_requirement_verified' => true,
                'checkout_odometer_km' => (int) $data['odometer_km'],
                'checkout_fuel_level_percent' => (int) $data['fuel_level_percent'],
                'customer_signature_sha256' => $customerSignature->sha256,
                'company_signature_sha256' => $companySignature->sha256,
            ],
        );

        $customerNotificationSent = $this->customerNotifications->notify(
            $company,
            $model,
            CarRentalCustomerNotificationService::CHECKED_OUT,
        );

        return response()->json([
            'data' => $this->reservationPayload($model, $access),
            'customer_notification_sent' => $customerNotificationSent,
        ]);
    }

    /**
     * Prolonge une location active sans toucher à une réservation future.
     * Un conflit est refusé avant toute écriture et ne divulgue aucun client.
     */
    public function extendReservation(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'due_at' => ['required', 'date'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $model = DB::transaction(function () use ($company, $access, $reservation, $data): CarRentalReservation {
            $model = $this->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'checked_out') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une location en circulation peut être prolongée.',
                ]);
            }

            $this->assertLockVersion($model, $data['expected_lock_version']);
            $dueAt = CarbonImmutable::parse($data['due_at'], $company->timezone)->utc();

            if ($dueAt->lessThanOrEqualTo(CarbonImmutable::instance($model->due_at))) {
                throw ValidationException::withMessages([
                    'due_at' => 'La nouvelle date de retour doit être postérieure au retour prévu.',
                ]);
            }

            $vehicle = $this->vehicleForReservation($company, $model);

            if ($vehicle->operational_status !== 'in_circulation') {
                throw ValidationException::withMessages([
                    'reservation' => 'Le véhicule doit être en circulation avant de prolonger la location.',
                ]);
            }

            try {
                $this->availability->assertVehiclePeriodAvailable(
                    $vehicle,
                    CarbonImmutable::instance($model->pickup_at),
                    $dueAt,
                    $model->id,
                    true,
                );
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'due_at' => 'La prolongation est impossible : ce véhicule a une réservation à venir. La réservation suivante n’a pas été modifiée.',
                ]);
            }

            $model->forceFill([
                'due_at' => $dueAt,
                'lock_version' => $model->lock_version + 1,
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile']);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_extended',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'site_id' => $model->site_id,
                'vehicle_id' => $model->vehicle_id,
                'billing_recalculated' => false,
            ],
        );

        $customerNotificationSent = $this->customerNotifications->notify(
            $company,
            $model,
            CarRentalCustomerNotificationService::EXTENDED,
        );

        return response()->json([
            'data' => $this->reservationPayload($model, $access),
            'customer_notification_sent' => $customerNotificationSent,
        ]);
    }

    /**
     * Enregistre le retour réel. Le contrat initial et son tarif restent
     * inchangés : aucune remise ou aucun remboursement n'est créé ici.
     */
    public function completeReturn(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $model = DB::transaction(function () use ($company, $access, $reservation, $data): CarRentalReservation {
            $model = $this->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'checked_out') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une location en circulation peut être retournée.',
                ]);
            }

            $this->assertLockVersion($model, $data['expected_lock_version']);
            $vehicle = $this->vehicleForReservation($company, $model);

            $model->forceFill([
                'state' => 'completed',
                'returned_at' => now()->utc(),
                'lock_version' => $model->lock_version + 1,
            ])->save();
            $vehicle->forceFill(['operational_status' => 'preparation'])->save();

            return $model->load(['site', 'vehicle', 'customerProfile']);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_return_recorded',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'site_id' => $model->site_id,
                'vehicle_id' => $model->vehicle_id,
                'billing_recalculated' => false,
                'vehicle_status' => 'preparation',
            ],
        );

        $customerNotificationSent = $this->customerNotifications->notify(
            $company,
            $model,
            CarRentalCustomerNotificationService::RETURN_RECORDED,
        );

        return response()->json([
            'data' => $this->reservationPayload($model, $access),
            'customer_notification_sent' => $customerNotificationSent,
        ]);
    }

    public function cancelReservation(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'reason_code' => ['required', Rule::in([
                'customer_request',
                'vehicle_unavailable',
                'business_decision',
                'other',
            ])],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $model = DB::transaction(function () use ($company, $access, $reservation, $data): CarRentalReservation {
            $model = $this->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'reserved') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une réservation non remise peut être annulée.',
                ]);
            }

            $this->assertLockVersion($model, $data['expected_lock_version']);
            $model->forceFill([
                'state' => 'cancelled',
                'lock_version' => $model->lock_version + 1,
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile']);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_cancelled',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'site_id' => $model->site_id,
                'reason_code' => $data['reason_code'],
                'financial_action_created' => false,
            ],
        );

        return response()->json([
            'data' => $this->reservationPayload($model, $access),
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
            'bank_name' => ['nullable', 'string', 'max:64'],
            'bank_reference' => ['nullable', 'string', 'max:128'],
            'proof_file_id' => ['nullable', 'uuid', 'required_if:method,bank_transfer'],
        ], [
            'cash_register_id.required_if' => 'Sélectionnez la caisse qui reçoit le paiement en espèces.',
            'proof_file_id.required_if' => 'Ajoutez la photo ou le fichier du reçu de virement Sogebank.',
        ]);

        if ($data['method'] === 'bank_transfer' && filled($data['bank_name'] ?? null) && strcasecmp((string) $data['bank_name'], 'Sogebank') !== 0) {
            throw ValidationException::withMessages([
                'bank_name' => 'Les virements Car Rental doivent être déposés à la Sogebank.',
            ]);
        }

        if ($data['method'] === 'credit') {
            if (! $access->allows('rental.payments.credit')) {
                return response()->json([
                    'message' => 'Seul un administrateur ou le propriétaire peut accorder un crédit.',
                ], 403);
            }

            if ($data['payment_kind'] !== 'rental') {
                throw ValidationException::withMessages([
                    'method' => 'Le dépôt de garantie ne peut pas être accordé à crédit.',
                ]);
            }
        }

        if ($data['payment_kind'] === 'security_deposit' && $data['currency'] !== 'USD') {
            throw ValidationException::withMessages([
                'currency' => 'Le dépôt minimum de cette version est contrôlé en USD. Enregistrez le dépôt de garantie en USD.',
            ]);
        }

        $proof = $data['method'] === 'bank_transfer'
            ? $this->files->find($company, $data['proof_file_id'] ?? null, StoredFile::PURPOSE_PAYMENT_PROOF, 'proof_file_id')
            : null;

        if ($proof !== null && $proof->site_id !== $model->site_id) {
            throw ValidationException::withMessages([
                'proof_file_id' => 'Le reçu doit être ajouté depuis l’adresse de la réservation.',
            ]);
        }

        $payment = DB::transaction(function () use ($company, $model, $data, $proof, $actor): CarRentalPayment {
            $cashRegisterId = $data['method'] === 'cash' ? ($data['cash_register_id'] ?? null) : null;

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

            // Un crédit n'encaisse rien : il est accordé et approuvé par la même personne autorisée.
            $isCredit = $data['method'] === 'credit';
            $payment = new CarRentalPayment([
                'company_id' => $company->id,
                'reservation_id' => $model->id,
                'cash_register_id' => $cashRegisterId,
                'payment_kind' => $data['payment_kind'],
                'method' => $data['method'],
                'status' => $isCredit ? 'approved' : 'submitted',
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'bank_name' => $data['method'] === 'bank_transfer' ? 'Sogebank' : null,
                'proof_file_id' => $proof?->id,
                'proof_storage_key' => $proof?->path,
                'proof_sha256' => $proof?->sha256,
                'approved_by' => $isCredit ? $actor?->id : null,
                'approved_at' => $isCredit ? now()->utc() : null,
            ]);
            $payment->setBankReference($data['method'] === 'bank_transfer' ? ($data['bank_reference'] ?? null) : null);
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

            if ($record->payment_kind === 'security_deposit') {
                CarRentalSecurityDeposit::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'payment_id' => $record->id,
                    ],
                    [
                        'reservation_id' => $model->id,
                        'method' => $record->method,
                        'status' => 'held',
                        'currency' => $record->currency,
                        'amount' => $record->amount,
                        'held_at' => $record->approved_at,
                        'released_at' => null,
                    ],
                );
            }

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

    /**
     * @param array<int, string> $relations
     */
    private function reservationFor(
        Company $company,
        CompanyUserAccess $access,
        string $reservation,
        array $relations = [],
        bool $forUpdate = false,
    ): CarRentalReservation {
        $query = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation);

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $model = $query->first();
        abort_if($model === null, 404, 'Réservation introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        return $model;
    }

    private function vehicleForReservation(Company $company, CarRentalReservation $reservation): CarRentalVehicle
    {
        $vehicle = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation->vehicle_id)
            ->lockForUpdate()
            ->first();

        if ($vehicle === null || $vehicle->site_id !== $reservation->site_id) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Le véhicule de cette réservation est introuvable pour cette adresse.',
            ]);
        }

        return $vehicle;
    }

    private function assertLockVersion(CarRentalReservation $reservation, int $expectedVersion): void
    {
        if ($reservation->lock_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'reservation' => 'Cette réservation a été modifiée par un autre utilisateur. Actualisez-la avant de continuer.',
            ]);
        }
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

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function calendarInterval(Company $company, string $fromValue, string $toValue): array
    {
        $from = CarbonImmutable::parse($fromValue, $company->timezone);
        $to = CarbonImmutable::parse($toValue, $company->timezone);

        if ($this->isDateOnly($fromValue)) {
            $from = $from->startOfDay();
        }

        if ($this->isDateOnly($toValue)) {
            // Le champ Fin est inclusif dans l'interface : la requête utilise
            // donc le début du jour suivant comme borne de fin exclusive.
            $to = $to->addDay()->startOfDay();
        }

        $from = $from->utc();
        $to = $to->utc();

        if ($to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages([
                'to' => 'La date de fin doit être postérieure ou égale à la date de début.',
            ]);
        }

        return [$from, $to];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function defaultCalendarInterval(Company $company): array
    {
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $from = $today->startOfMonth();
        $monthEnd = $today->endOfMonth()->startOfDay();
        $to = $monthEnd->addDay();

        // Pendant les sept derniers jours, la vue comprend aussi les sept
        // premiers jours du mois suivant afin d'anticiper les retours.
        if ($today->greaterThanOrEqualTo($monthEnd->subDays(6))) {
            $to = $to->addDays(7);
        }

        return [$from->utc(), $to->utc()];
    }

    private function isDateOnly(string $value): bool
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1;
    }

    private function reservationSearchTerm(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $search = strtoupper((string) preg_replace('/\s+/', '', trim($value)));

        return $search === '' ? null : $search;
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
            'reference_photo' => $vehicle->referencePhoto(),
            'latest_odometer_km' => $vehicle->latest_odometer_km,
            'daily_rate_usd' => $vehicle->daily_rate_usd,
            'minimum_security_deposit_usd' => $vehicle->minimum_security_deposit_usd,
            'is_active' => $vehicle->is_active,
            'color' => $vehicle->color,
            'fuel_type' => $vehicle->fuel_type,
            'transmission' => $vehicle->transmission,
            'engine_displacement_cc' => $vehicle->engine_displacement_cc,
            'doors' => $vehicle->doors,
            'photo' => $vehicle->photo_file_id === null ? null : [
                'id' => $vehicle->photo_file_id,
                'url' => '/api/v1/car-rental/files/' . $vehicle->photo_file_id,
            ],
        ];

        if ($includeManagementDetails) {
            $payload['registration_number'] = $vehicle->registration_number ?: $vehicle->code;
            $payload['registration_status'] = $vehicle->registration_status ?: 'normal';
            $payload['vin'] = $vehicle->vin;
            $payload['document_statuses'] = $company !== null
                ? $this->vehicleDocumentStatuses($vehicle, $company)
                : [];
        }

        return $payload;
    }

    private function assertVehicleCommercialTermsConfigured(CarRentalVehicle $vehicle): void
    {
        if ($vehicle->daily_rate_usd === null || (float) $vehicle->daily_rate_usd <= 0) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Le tarif quotidien en USD doit être configuré pour ce véhicule avant toute réservation.',
            ]);
        }

        if ($vehicle->minimum_security_deposit_usd === null || (float) $vehicle->minimum_security_deposit_usd < 0) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Le dépôt minimum en USD doit être configuré pour ce véhicule avant toute réservation.',
            ]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    private function vehicleContractRules(): array
    {
        return [
            'color' => ['nullable', 'string', 'max:48'],
            'fuel_type' => ['nullable', Rule::in(CarRentalVehicle::FUEL_TYPES)],
            'transmission' => ['nullable', Rule::in(CarRentalVehicle::TRANSMISSIONS)],
            'engine_displacement_cc' => ['nullable', 'integer', 'between:50,10000'],
            'doors' => ['nullable', 'integer', 'between:2,6'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function vehicleContractAttributes(array $data): array
    {
        return [
            'color' => $this->nullableTrimmed($data['color'] ?? null),
            'fuel_type' => $data['fuel_type'] ?? null,
            'transmission' => $data['transmission'] ?? null,
            'engine_displacement_cc' => $data['engine_displacement_cc'] ?? null,
            'doors' => $data['doors'] ?? null,
        ];
    }

    /**
     * Le tarif vient de la fiche véhicule. Seul un rôle autorisé
     * (rental.reservations.override_rate) peut appliquer un autre montant ou
     * une autre devise. Retourne vrai lorsqu'un tarif particulier est appliqué.
     */
    private function assertRateAllowed(
        CompanyUserAccess $access,
        CarRentalVehicle $vehicle,
        string $currency,
        string $dailyRate,
    ): bool {
        $matchesVehicle = $currency === 'USD'
            && (int) round((float) $dailyRate * 100) === (int) round((float) $vehicle->daily_rate_usd * 100);

        if ($matchesVehicle) {
            return false;
        }

        if (! $access->allows('rental.reservations.override_rate')) {
            throw ValidationException::withMessages([
                'daily_rate' => sprintf(
                    'Le tarif de la fiche véhicule s’applique : USD %s par jour. Seul un administrateur peut appliquer un autre tarif.',
                    number_format((float) $vehicle->daily_rate_usd, 2, '.', ''),
                ),
            ]);
        }

        return true;
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
            'registration_status.in' => 'Sélectionnez « Démonstration », « Location » ou « Normale ».',
            'reference_photo_key.in' => 'La photo de référence sélectionnée n’est pas disponible.',
            'vin.max' => 'Le VIN ne peut pas dépasser 64 caractères.',
            'vin.unique' => 'Ce VIN est déjà utilisé par un autre véhicule de cette société.',
            'latest_odometer_km.required' => 'Saisissez le kilométrage actuel.',
            'latest_odometer_km.integer' => 'Le kilométrage doit être un nombre entier.',
            'latest_odometer_km.min' => 'Le kilométrage ne peut pas être négatif.',
            'daily_rate_usd.required' => 'Saisissez le tarif quotidien en USD.',
            'daily_rate_usd.numeric' => 'Le tarif quotidien doit être un montant valide.',
            'daily_rate_usd.gt' => 'Le tarif quotidien doit être supérieur à zéro.',
            'fuel_type.in' => 'Choisissez Essence ou Diesel.',
            'transmission.in' => 'Choisissez Manuelle ou Automatique.',
            'engine_displacement_cc.between' => 'Indiquez une cylindrée en cm³ entre 50 et 10 000.',
            'doors.between' => 'Indiquez un nombre de portes entre 2 et 6.',
            'minimum_security_deposit_usd.required' => 'Saisissez le dépôt minimum en USD.',
            'minimum_security_deposit_usd.numeric' => 'Le dépôt minimum doit être un montant valide.',
            'minimum_security_deposit_usd.gte' => 'Le dépôt minimum ne peut pas être négatif.',
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

    /** @return array<string, mixed> */
    private function reservationListPayload(CarRentalReservation $reservation): array
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
            'customer' => $reservation->customerProfile === null ? null : [
                'id' => $reservation->customerProfile->id,
                'display_name' => $reservation->customerProfile->display_name,
                'customer_type' => $reservation->customerProfile->customer_type,
            ],
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
    /**
     * Copie figée de ce qui figure au contrat : identité du loueur,
     * conditions générales et caractéristiques du véhicule au moment de
     * la signature. Une modification ultérieure ne change pas le contrat.
     *
     * @return array<string, mixed>
     */
    private function contractSnapshot(Company $company, CarRentalVehicle $vehicle): array
    {
        return [
            'lessor' => [
                'name' => $company->legal_name,
                'display_name' => $company->display_name,
                'representative' => $company->legal_representative,
                'tax_identification_number' => $company->tax_identification_number,
                'address' => $company->legal_address,
                'phone_numbers' => $company->phone_numbers,
            ],
            'terms' => (string) $company->rental_contract_terms,
            'terms_sha256' => hash('sha256', (string) $company->rental_contract_terms),
            'vehicle' => [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'model_year' => $vehicle->model_year,
                'registration_number' => $vehicle->registration_number ?: $vehicle->code,
                'vin' => $vehicle->vin,
                'color' => $vehicle->color,
                'fuel_type' => $vehicle->fuel_type,
                'transmission' => $vehicle->transmission,
                'engine_displacement_cc' => $vehicle->engine_displacement_cc,
                'doors' => $vehicle->doors,
                'category' => $vehicle->category,
            ],
            'timezone' => $company->timezone,
        ];
    }

    private function fileUrl(?string $fileId): ?string
    {
        return $fileId === null ? null : '/api/v1/car-rental/files/' . $fileId;
    }

    /** @return array<string, mixed>|null */
    private function checkoutInspectionPayload(CarRentalReservation $reservation, bool $includeSignatures): ?array
    {
        $inspection = $reservation->relationLoaded('inspections')
            ? $reservation->inspections->firstWhere('stage', 'pre_rental')
            : $reservation->inspections()->where('stage', 'pre_rental')->first();

        if (! $inspection instanceof CarRentalInspection || $inspection->status !== 'finalized') {
            return null;
        }

        return [
            'inspected_at' => $inspection->inspected_at?->toIso8601String(),
            'odometer_km' => $inspection->odometer_km,
            'fuel_level_percent' => $inspection->fuel_level_percent === null ? null : (int) round((float) $inspection->fuel_level_percent),
            'accessories' => $inspection->accessories ?? [],
            'damage_notes' => $includeSignatures ? $inspection->notes : null,
            'photo_urls' => array_map(fn (string $id): string => (string) $this->fileUrl($id), $inspection->photo_file_ids ?? []),
            'company_signer_name' => $inspection->company_signer_name,
            'customer_signed_at' => $inspection->customer_signed_at?->toIso8601String(),
            'company_signed_at' => $inspection->company_signed_at?->toIso8601String(),
            'customer_signature_url' => $includeSignatures ? $this->fileUrl($inspection->customer_signature_file_id) : null,
            'company_signature_url' => $includeSignatures ? $this->fileUrl($inspection->company_signature_file_id) : null,
        ];
    }

    /**
     * Rattache le contrat PDF signé, généré à partir de la copie figée.
     * Le contrat est définitif : il ne peut pas être remplacé.
     */
    public function attachContract(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'file_id' => ['required', 'uuid'],
            'send_to_customer' => ['sometimes', 'boolean'],
        ]);

        $file = $this->files->find($company, $data['file_id'], StoredFile::PURPOSE_RENTAL_CONTRACT, 'file_id');

        $model = DB::transaction(function () use ($company, $access, $reservation, $file): CarRentalReservation {
            $model = $this->reservationFor($company, $access, $reservation, [], true);

            if (! in_array($model->state, ['checked_out', 'completed'], true) || $model->contract_snapshot === null) {
                throw ValidationException::withMessages([
                    'reservation' => 'Le contrat ne peut être émis qu’après la mise en circulation signée.',
                ]);
            }

            if ($model->contract_file_id !== null) {
                throw ValidationException::withMessages([
                    'reservation' => 'Le contrat signé de cette réservation est déjà émis.',
                ]);
            }

            $model->forceFill([
                'contract_file_id' => $file->id,
                'contract_issued_at' => now()->utc(),
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections']);
        });

        $this->audit->record(
            eventType: 'car_rental.contract_issued',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'contract_sha256' => $file->sha256,
                'terms_sha256' => $model->contract_snapshot['terms_sha256'] ?? null,
            ],
        );

        $sent = false;

        // Le contrat contient la plaque et le numéro de permis : il n'est
        // envoyé par courriel que sur demande explicite.
        if (($data['send_to_customer'] ?? false) === true) {
            $content = Storage::disk($file->disk)->get($file->path);
            $sent = is_string($content) && $this->customerNotifications->notifySignedContract($company, $model, [[
                'name' => 'Contrat-' . $model->reservation_number . '.pdf',
                'content' => $content,
                'mime' => 'application/pdf',
            ]]);
        }

        return response()->json([
            'data' => $this->reservationPayload($model, $access),
            'customer_notification_sent' => $sent,
        ]);
    }

    private function reservationPayload(CarRentalReservation $reservation, ?CompanyUserAccess $access = null): array
    {
        // Les coordonnées du client ne sont renvoyées qu'aux rôles qui gèrent la réservation.
        $includeContact = $access?->allows('rental.reservations.manage') ?? false;
        $includeDocuments = $access?->allows('rental.documents.sensitive') ?? false;

        $airportPickupFee = (float) $reservation->airport_pickup_fee_usd;
        $airportDropoffFee = (float) $reservation->airport_dropoff_fee_usd;

        return [
            'id' => $reservation->id,
            'number' => $reservation->formattedNumber(),
            'state' => $reservation->state,
            'site_id' => $reservation->site_id,
            'site' => $reservation->relationLoaded('site') && $reservation->site !== null ? [
                'id' => $reservation->site->id,
                'code' => $reservation->site->code,
                'name' => $reservation->site->name,
            ] : null,
            'pickup_at' => $reservation->pickup_at?->toIso8601String(),
            'due_at' => $reservation->due_at?->toIso8601String(),
            'checked_out_at' => $reservation->checked_out_at?->toIso8601String(),
            'returned_at' => $reservation->returned_at?->toIso8601String(),
            'lock_version' => $reservation->lock_version,
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
            'rate_overridden' => (bool) $reservation->rate_overridden,
            'minimum_security_deposit_usd' => $reservation->minimum_security_deposit_usd,
            'kilometer_plan' => $reservation->kilometer_plan,
            'included_km' => $reservation->included_km,
            'additional_km_rate' => $reservation->additional_km_rate,
            'driver_full_name' => $reservation->driver_full_name,
            'driver_license_expires_at' => $reservation->driver_license_expires_at?->format('Y-m-d'),
            'driver_license_verified' => $reservation->driver_license_verified_at !== null,
            'vehicle' => $reservation->vehicle === null ? null : $this->vehiclePayload($reservation->vehicle),
            'customer' => $reservation->customerProfile === null ? null : [
                'id' => $reservation->customerProfile->id,
                'display_name' => $reservation->customerProfile->display_name,
                'customer_type' => $reservation->customerProfile->customer_type,
                ...($includeContact ? [
                    'email' => $reservation->customerProfile->email,
                    'phone' => $reservation->customerProfile->phone,
                ] : []),
            ],
            'payments' => $reservation->relationLoaded('payments')
                ? $reservation->payments->map(fn (CarRentalPayment $payment): array => $this->paymentPayload($payment))
                : null,
            'security_deposits' => $reservation->relationLoaded('securityDeposits')
                ? $reservation->securityDeposits->map(fn (CarRentalSecurityDeposit $deposit): array => $this->securityDepositPayload($deposit))
                : null,
            'checkout_requirements' => $this->checkoutRequirements($reservation),
            'driver_license' => $includeContact && $reservation->driver_license_country !== null ? [
                'country' => $reservation->driver_license_country,
                'subdivision' => $reservation->driver_license_subdivision,
                'number' => $reservation->driver_license_number,
                'expires_at' => $reservation->driver_license_expires_at?->format('Y-m-d'),
                'front_url' => $includeDocuments ? $this->fileUrl($reservation->driver_license_front_file_id) : null,
                'back_url' => $includeDocuments ? $this->fileUrl($reservation->driver_license_back_file_id) : null,
            ] : null,
            'additional_driver' => $includeContact && filled($reservation->additional_driver_name) ? [
                'name' => $reservation->additional_driver_name,
                'license_number' => $reservation->additional_driver_license_number,
            ] : null,
            'checkout_inspection' => in_array($reservation->state, ['checked_out', 'completed'], true)
                ? $this->checkoutInspectionPayload($reservation, $includeContact)
                : null,
            'contract' => [
                'issued_at' => $reservation->contract_issued_at?->toIso8601String(),
                'file_url' => $includeContact ? $this->fileUrl($reservation->contract_file_id) : null,
                'snapshot' => $includeContact ? $reservation->contract_snapshot : null,
            ],
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
            'proof_file_url' => $payment->proof_file_id === null ? null : '/api/v1/car-rental/files/' . $payment->proof_file_id,
            'submitted_at' => $payment->submitted_at?->toIso8601String(),
            'approved_at' => $payment->approved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function securityDepositPayload(CarRentalSecurityDeposit $deposit): array
    {
        return [
            'id' => $deposit->id,
            'payment_id' => $deposit->payment_id,
            'method' => $deposit->method,
            'status' => $deposit->status,
            'currency' => $deposit->currency,
            'amount' => $deposit->amount,
            'held_at' => $deposit->held_at?->toIso8601String(),
            'released_at' => $deposit->released_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function checkoutRequirements(CarRentalReservation $reservation): array
    {
        $payments = $reservation->relationLoaded('payments')
            ? $reservation->payments
            : $reservation->payments()->get();
        $deposits = $reservation->relationLoaded('securityDeposits')
            ? $reservation->securityDeposits
            : $reservation->securityDeposits()->get();
        $minimumDeposit = (float) ($reservation->minimum_security_deposit_usd ?? 0);
        $heldDepositUsd = (float) $deposits
            ->filter(static fn (CarRentalSecurityDeposit $deposit): bool => $deposit->status === 'held' && $deposit->currency === 'USD')
            ->sum(static fn (CarRentalSecurityDeposit $deposit): float => (float) $deposit->amount);

        return [
            'contract_terms_configured' => filled(Company::query()->whereKey($reservation->company_id)->value('rental_contract_terms')),
            'driver_license_verified' => $reservation->driver_license_verified_at !== null,
            'minimum_security_deposit_configured' => $reservation->minimum_security_deposit_usd !== null,
            'approved_rental_payment' => $payments->contains(
                static fn (CarRentalPayment $payment): bool => $payment->payment_kind === 'rental' && $payment->status === 'approved',
            ),
            'minimum_security_deposit_usd' => number_format($minimumDeposit, 2, '.', ''),
            'held_security_deposit_usd' => number_format($heldDepositUsd, 2, '.', ''),
            'security_deposit_satisfied' => $heldDepositUsd + 0.0001 >= $minimumDeposit,
        ];
    }
}
