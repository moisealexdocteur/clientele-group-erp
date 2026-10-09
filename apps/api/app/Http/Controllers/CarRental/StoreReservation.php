<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Models\CarRentalVehicle;
use App\Support\AuditLogger;
use App\Support\CarRentalAvailabilityService;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalCustomerDirectory;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalPricing;
use App\Support\CarRental\CarRentalSchedule;
use App\Support\CarRental\CarRentalVehicleRules;
use App\Support\CompanySiteAuthorizer;
use App\Support\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class StoreReservation extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalCustomerDirectory $customers,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalPricing $pricing,
        private readonly CarRentalSchedule $schedule,
        private readonly CarRentalVehicleRules $vehicleRules,
        private readonly AuditLogger $audit,
        private readonly CarRentalAvailabilityService $availability,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
        private readonly DocumentNumberService $documentNumbers,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
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
        [$pickupAt, $dueAt] = $this->schedule->interval($company, $data['pickup_at'], $data['due_at']);
        $airportPickupFee = $this->pricing->airportServiceFee(
            $data['pickup_location_type'],
            (bool) ($data['apply_airport_pickup_fee'] ?? false),
            'apply_airport_pickup_fee',
        );
        $airportDropoffFee = $this->pricing->airportServiceFee(
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
            $this->vehicleRules->assertVehicleCommercialTermsConfigured($vehicle);
            $rateOverridden = $this->vehicleRules->assertRateAllowed($access, $vehicle, $data['currency'], (string) $data['daily_rate']);

            $customer = $this->customers->resolveCustomer($company, $data);
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
                'pickup_location_detail' => $this->pricing->locationDetail($data['pickup_location_type'], $data['pickup_location_detail'] ?? null, $site),
                'dropoff_location_type' => $data['dropoff_location_type'],
                'dropoff_location_detail' => $this->pricing->locationDetail($data['dropoff_location_type'], $data['dropoff_location_detail'] ?? null, $site),
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
            'data' => $this->presenter->reservationPayload($reservation->load(['vehicle', 'customerProfile']), $access),
            'customer_notification_sent' => $customerNotificationSent,
        ], 201);
    }
}
