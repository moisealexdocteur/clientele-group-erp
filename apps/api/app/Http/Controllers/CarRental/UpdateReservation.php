<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Support\AuditLogger;
use App\Support\CarRentalAvailabilityService;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalPricing;
use App\Support\CarRental\CarRentalSchedule;
use App\Support\CarRental\CarRentalVehicleRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateReservation extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalPricing $pricing,
        private readonly CarRentalSchedule $schedule,
        private readonly CarRentalVehicleRules $vehicleRules,
        private readonly AuditLogger $audit,
        private readonly CarRentalAvailabilityService $availability,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
    ) {
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
    public function __invoke(Request $request, string $reservation): JsonResponse
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
        [$pickupAt, $dueAt] = $this->schedule->interval($company, $data['pickup_at'], $data['due_at']);

        /** @var array<int, string> $changes */
        $changes = [];

        $model = DB::transaction(function () use ($company, $access, $reservation, $data, $pickupAt, $dueAt, &$changes): CarRentalReservation {
            $model = $this->lookup->reservationFor($company, $access, $reservation, ['site', 'customerProfile'], true);

            if ($model->state !== 'reserved') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une réservation non remise peut être modifiée.',
                ]);
            }

            $this->lookup->assertLockVersion($model, $data['expected_lock_version']);
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
                $this->vehicleRules->assertVehicleCommercialTermsConfigured($vehicle);
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
                $rateOverridden = $this->vehicleRules->assertRateAllowed($access, $vehicle, $currency, $dailyRate);
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
                $attributes['pickup_location_detail'] = $this->pricing->locationDetail($pickupType, $data['pickup_location_detail'] ?? null, $site);
                $attributes['dropoff_location_type'] = $dropoffType;
                $attributes['dropoff_location_detail'] = $this->pricing->locationDetail($dropoffType, $data['dropoff_location_detail'] ?? null, $site);
                $attributes['airport_pickup_fee_usd'] = $this->pricing->airportServiceFee(
                    $pickupType,
                    (bool) ($data['apply_airport_pickup_fee'] ?? false),
                    'apply_airport_pickup_fee',
                );
                $attributes['airport_dropoff_fee_usd'] = $this->pricing->airportServiceFee(
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
            'data' => $this->presenter->reservationPayload($model, $access),
            'customer_notification_sent' => $notificationSent,
        ]);
    }
}
