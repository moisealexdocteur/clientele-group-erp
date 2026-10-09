<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalInspection;
use App\Models\CarRentalReservation;
use App\Models\StoredFile;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalInspectionRules;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalPricing;
use App\Support\FileVault;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CompleteReturn extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalInspectionRules $inspectionRules,
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalPricing $pricing,
        private readonly AuditLogger $audit,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
        private readonly FileVault $files,
    ) {
    }

    /**
     * Enregistre le retour réel avec la fiche de retour : kilométrage,
     * carburant, accessoires, dommages et croquis. Le tarif initial reste
     * inchangé (un retour anticipé conserve le montant prévu). Les frais
     * supplémentaires ne sont appliqués que s'ils sont cochés au retour.
     */
    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'fuel_level_percent' => ['required', 'integer', Rule::in(CarRentalInspection::FUEL_LEVELS)],
            'accessories' => ['present', 'array'],
            'accessories.*' => ['string', Rule::in(CarRentalInspection::ACCESSORIES)],
            'damage_notes' => ['nullable', 'string', 'max:2000'],
            ...$this->inspectionRules->damageMarkRules(),
            'inspection_photo_file_ids' => ['nullable', 'array', 'max:12'],
            'inspection_photo_file_ids.*' => ['uuid'],
            'apply_cleaning_fee' => ['sometimes', 'boolean'],
            'apply_extra_km' => ['sometimes', 'boolean'],
            'other_charges' => ['nullable', 'array', 'max:5'],
            'other_charges.*.label' => ['required', 'string', 'max:80'],
            'other_charges.*.amount' => ['required', 'numeric', 'min:0.01', 'max:99999', new DecimalAmount()],
            'customer_signature_file_id' => ['nullable', 'uuid'],
        ], [
            'odometer_km.required' => 'Saisissez le kilométrage au retour.',
            'fuel_level_percent.required' => 'Indiquez le niveau de carburant au retour.',
            'fuel_level_percent.in' => 'Indiquez le niveau de carburant au retour.',
            'other_charges.*.label.required' => 'Indiquez le motif de chaque frais.',
            'other_charges.*.amount.min' => 'Le montant d’un frais doit être supérieur à zéro.',
        ]);

        if (($data['other_charges'] ?? []) !== [] && ! $access->allows('rental.deposits.settle')) {
            return response()->json(['message' => 'Votre rôle ne permet pas d’ajouter d’autres frais. Demandez à un administrateur.'], 403);
        }

        $customerSignature = filled($data['customer_signature_file_id'] ?? null)
            ? $this->files->find($company, $data['customer_signature_file_id'], StoredFile::PURPOSE_SIGNATURE, 'customer_signature_file_id')
            : null;
        $photoIds = $this->lookup->inspectionPhotoIds($company, $data['inspection_photo_file_ids'] ?? []);

        $model = DB::transaction(function () use ($company, $access, $reservation, $data, $actor, $customerSignature, $photoIds): CarRentalReservation {
            $model = $this->lookup->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'checked_out') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une location en circulation peut être retournée.',
                ]);
            }

            $this->lookup->assertLockVersion($model, $data['expected_lock_version']);
            $vehicle = $this->lookup->vehicleForReservation($company, $model);
            $checkout = $model->inspections()->where('stage', 'pre_rental')->first();
            $departureKm = $checkout instanceof CarRentalInspection && $checkout->odometer_km !== null
                ? (int) $checkout->odometer_km
                : (int) $vehicle->latest_odometer_km;
            $returnKm = (int) $data['odometer_km'];

            if ($returnKm < $departureKm) {
                throw ValidationException::withMessages([
                    'odometer_km' => sprintf(
                        'Le kilométrage au retour ne peut pas être inférieur au kilométrage au départ (%s km).',
                        number_format($departureKm, 0, ',', ' '),
                    ),
                ]);
            }

            $charges = $this->pricing->returnCharges($company, $model, $returnKm - $departureKm, $data);
            $now = now()->utc();

            CarRentalInspection::query()->updateOrCreate(
                ['company_id' => $company->id, 'reservation_id' => $model->id, 'stage' => 'post_rental'],
                [
                    'vehicle_id' => $vehicle->id,
                    'inspector_user_id' => $actor?->id,
                    'status' => 'finalized',
                    'inspected_at' => $now,
                    'odometer_km' => $returnKm,
                    'fuel_level_percent' => (int) $data['fuel_level_percent'],
                    'accessories' => array_values(array_intersect(CarRentalInspection::ACCESSORIES, $data['accessories'])),
                    'notes' => Text::nullableTrimmed($data['damage_notes'] ?? null),
                    'damage_sketch' => $this->inspectionRules->damageMarks($data),
                    'photo_file_ids' => $photoIds,
                    'company_signer_name' => $actor?->name,
                    'company_signed_at' => $now,
                    'customer_signed_at' => $customerSignature === null ? null : $now,
                    'customer_signature_file_id' => $customerSignature?->id,
                    'customer_signature_sha256' => $customerSignature?->sha256,
                ],
            );

            $model->forceFill([
                'state' => 'completed',
                'returned_at' => $now,
                'additional_charges' => $charges,
                'lock_version' => $model->lock_version + 1,
            ])->save();
            $vehicle->forceFill([
                'operational_status' => 'preparation',
                'latest_odometer_km' => max($returnKm, (int) $vehicle->latest_odometer_km),
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections']);
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
                'return_odometer_km' => (int) $data['odometer_km'],
                'return_fuel_level_percent' => (int) $data['fuel_level_percent'],
                'damage_mark_count' => count($this->inspectionRules->damageMarks($data)),
                'additional_charges' => array_map(
                    static fn (array $charge): array => ['code' => $charge['code'], 'amount' => $charge['amount']],
                    $model->additional_charges ?? [],
                ),
            ],
        );

        $customerNotificationSent = $this->customerNotifications->notify(
            $company,
            $model,
            CarRentalCustomerNotificationService::RETURN_RECORDED,
        );

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
            'customer_notification_sent' => $customerNotificationSent,
        ]);
    }
}
