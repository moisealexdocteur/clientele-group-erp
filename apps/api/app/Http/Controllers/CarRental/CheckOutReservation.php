<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalInspection;
use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CarRentalSecurityDeposit;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\CarRentalAvailabilityService;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalInspectionRules;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalPricing;
use App\Support\CarRental\CarRentalVehicleRules;
use App\Support\FileVault;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CheckOutReservation extends Controller
{
    use ResolvesCompanyAccess;

    /**
     * Pays dont les permis sont délivrés par un État, une province ou un
     * territoire. Ailleurs (Haïti, France, etc.), le permis est national.
     */
    private const LICENSE_SUBDIVISION_COUNTRIES = ['US', 'CA', 'MX', 'AU', 'BR', 'IN'];

    public function __construct(
        private readonly CarRentalInspectionRules $inspectionRules,
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalPricing $pricing,
        private readonly CarRentalVehicleRules $vehicleRules,
        private readonly AuditLogger $audit,
        private readonly CarRentalAvailabilityService $availability,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
        private readonly FileVault $files,
    ) {
    }

    public function __invoke(Request $request, string $reservation): JsonResponse
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
            ...$this->inspectionRules->damageMarkRules(),
            'terms_accepted' => ['accepted'],
            'customer_signature_file_id' => ['required', 'uuid'],
            'company_signature_file_id' => ['required', 'uuid'],
            'company_signer_name' => ['required', 'string', 'max:160'],
            // Le courriel de remise part avec le contrat signé, une fois le PDF rattaché.
            'defer_customer_notification' => ['sometimes', 'boolean'],
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
        $inspectionPhotoIds = $this->lookup->inspectionPhotoIds($company, $data['inspection_photo_file_ids'] ?? []);

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
            $model = $this->lookup->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'reserved') {
                throw ValidationException::withMessages([
                    'reservation' => 'Cette réservation ne peut pas être mise en circulation.',
                ]);
            }

            $this->lookup->assertLockVersion($model, $data['expected_lock_version']);
            $vehicle = $this->lookup->vehicleForReservation($company, $model);
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
                $this->vehicleRules->assertVehicleCommercialTermsConfigured($vehicle);
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
                'driver_full_name' => Text::nullableTrimmed($data['driver_full_name']),
                'driver_license_number' => Text::nullableTrimmed($data['driver_license_number']),
                'driver_license_expires_at' => $licenseExpiration,
                'driver_license_verified_at' => $now,
                'driver_license_country' => $data['driver_license_country'],
                'driver_license_subdivision' => in_array($data['driver_license_country'], self::LICENSE_SUBDIVISION_COUNTRIES, true)
                    ? Text::nullableTrimmed($data['driver_license_subdivision'] ?? null)
                    : null,
                'driver_license_front_file_id' => $licenseFront->id,
                'driver_license_back_file_id' => $licenseBack->id,
                'additional_driver_name' => Text::nullableTrimmed($data['additional_driver_name'] ?? null),
                'additional_driver_license_number' => filled($data['additional_driver_name'] ?? null)
                    ? Text::nullableTrimmed($data['additional_driver_license_number'] ?? null)
                    : null,
                'contract_snapshot' => $this->pricing->contractSnapshot($company, $vehicle),
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
                    'notes' => Text::nullableTrimmed($data['damage_notes'] ?? null),
                    'damage_sketch' => $this->inspectionRules->damageMarks($data),
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

        $customerNotificationSent = ($data['defer_customer_notification'] ?? false) === true
            ? false
            : $this->customerNotifications->notify(
                $company,
                $model,
                CarRentalCustomerNotificationService::CHECKED_OUT,
            );

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
            'customer_notification_sent' => $customerNotificationSent,
        ]);
    }
}
