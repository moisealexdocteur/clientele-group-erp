<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CarRentalVehicle;
use App\Models\CarRentalReservation;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Models\User;
use App\Mail\CarRentalCustomerNotificationMail;
use App\Support\CarRentalCustomerNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class CarRentalReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_agent_can_reserve_an_available_vehicle_with_a_numbered_reference(): void
    {
        [$user, $company, $site, $token] = $this->context([
            'rental.availability.read',
            'rental.reservations.create',
            'rental.reservations.read',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-01', 'suv');

        $response = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.number', '0000 0001')
            ->assertJsonPath('data.state', 'reserved')
            ->assertJsonPath('data.vehicle.code', 'SUV-01')
            ->assertJsonPath('data.pickup_location.detail', 'Bureau Cap-Haïtien · Cap-Haïtien')
            ->assertJsonPath('data.dropoff_location.detail', 'Aéroport International du Cap-Haïtien')
            ->assertJsonPath('data.customer.display_name', 'Jean Pierre');

        $this->assertDatabaseHas('car_rental_reservations', [
            'company_id' => $company->id,
            'vehicle_id' => $vehicle->id,
            'reservation_number' => '00000001',
            'state' => 'reserved',
        ]);

        $event = AuditEvent::query()->where('event_type', 'car_rental.reservation_created')->firstOrFail();
        self::assertSame($company->id, $event->company_id);
        self::assertArrayNotHasKey('email', $event->metadata);
        self::assertArrayNotHasKey('phone', $event->metadata);
    }

    public function test_a_reservation_email_uses_the_customer_notification_template(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-MAIL', 'suv');

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('customer_notification_sent', true);

        Mail::assertSent(CarRentalCustomerNotificationMail::class, function (CarRentalCustomerNotificationMail $mail): bool {
            return $mail->reservationNumber === '0000 0001'
                && $mail->subjectLine === 'Votre réservation est confirmée - Clientèle Group'
                && ($mail->details['Véhicule'] ?? null) === 'Toyota Test'
                && ! in_array('SUV-MAIL', $mail->details, true)
                && str_ends_with((string) $mail->vehicleImageUrl, '/vehicle-images/car-rental-suv.svg')
                && $mail->pdfAttachments === [];
        });
    }

    public function test_an_invoice_notification_requires_a_valid_pdf_before_it_is_sent(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-INVOICE', 'suv');
        $reservationId = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data.id');

        Mail::fake();
        $reservation = CarRentalReservation::query()
            ->with(['vehicle', 'customerProfile'])
            ->findOrFail($reservationId);
        $notifications = app(CarRentalCustomerNotificationService::class);

        self::assertFalse($notifications->notifyInvoice($company, $reservation, []));
        Mail::assertNothingSent();

        self::assertTrue($notifications->notifyInvoice($company, $reservation, [[
            'name' => 'facture-0000-0001.pdf',
            'content' => '%PDF-1.7\nClientèle Group',
            'mime' => 'application/pdf',
        ]]));

        Mail::assertSent(CarRentalCustomerNotificationMail::class, function (CarRentalCustomerNotificationMail $mail): bool {
            return $mail->subjectLine === 'Votre facture est disponible - Clientèle Group'
                && count($mail->pdfAttachments) === 1
                && $mail->pdfAttachments[0]['name'] === 'facture-0000-0001.pdf';
        });

        $audit = AuditEvent::query()
            ->where('event_type', 'car_rental.customer_notification_skipped_no_pdf')
            ->firstOrFail();
        self::assertArrayNotHasKey('email', $audit->metadata);
        self::assertArrayNotHasKey('phone', $audit->metadata);
    }

    public function test_a_signed_contract_notification_requires_a_valid_pdf_before_it_is_sent(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-CONTRACT', 'suv');
        $reservationId = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data.id');

        Mail::fake();
        $reservation = CarRentalReservation::query()
            ->with(['vehicle', 'customerProfile'])
            ->findOrFail($reservationId);
        $notifications = app(CarRentalCustomerNotificationService::class);

        self::assertFalse($notifications->notifySignedContract($company, $reservation, []));
        Mail::assertNothingSent();

        self::assertTrue($notifications->notifySignedContract($company, $reservation, [[
            'name' => 'contrat-signe-0000-0001.pdf',
            'content' => '%PDF-1.7\nClientèle Group',
            'mime' => 'application/pdf',
        ]]));

        Mail::assertSent(CarRentalCustomerNotificationMail::class, function (CarRentalCustomerNotificationMail $mail): bool {
            return $mail->subjectLine === 'Votre contrat de location signé est disponible - Clientèle Group'
                && count($mail->pdfAttachments) === 1
                && $mail->pdfAttachments[0]['name'] === 'contrat-signe-0000-0001.pdf';
        });
    }

    public function test_an_overlapping_reservation_is_refused_and_the_vehicle_can_be_selected_by_category(): void
    {
        [, $company, $site, $token] = $this->context([
            'rental.availability.read',
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'PICK-01', 'pickup');

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'category' => 'pickup',
            ]))
            ->assertCreated();

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'category' => 'pickup',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vehicle_id');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/availability?' . http_build_query([
                'site_id' => $site->id,
                'pickup_at' => '2026-11-02T10:00:00-04:00',
                'due_at' => '2026-11-05T10:00:00-04:00',
                'category' => 'pickup',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_agent_can_apply_optional_airport_service_fees_in_usd(): void
    {
        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-AIRPORT', 'suv');

        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'pickup_location_type' => 'cap_haitien_airport',
                'dropoff_location_type' => 'cap_haitien_airport',
                'apply_airport_pickup_fee' => true,
                'apply_airport_dropoff_fee' => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.airport_pickup_fee_usd', '20.00')
            ->assertJsonPath('data.airport_dropoff_fee_usd', '20.00')
            ->assertJsonPath('data.airport_fees_total_usd', '40.00')
            ->json('data');

        $this->assertDatabaseHas('car_rental_reservations', [
            'id' => $reservation['id'],
            'airport_pickup_fee_usd' => '20.00',
            'airport_dropoff_fee_usd' => '20.00',
        ]);

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'pickup_location_type' => 'site',
                'apply_airport_pickup_fee' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('apply_airport_pickup_fee');
    }

    public function test_a_selected_site_scope_cannot_read_or_create_outside_its_grant(): void
    {
        [$user, $company, $site, $token, $access] = $this->context([
            'rental.availability.read',
        ], 'selected');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/availability?' . http_build_query([
                'site_id' => $site->id,
                'pickup_at' => '2026-11-01T10:00:00-04:00',
                'due_at' => '2026-11-02T10:00:00-04:00',
            ]))
            ->assertForbidden();

        CompanyUserSiteAccess::query()->create([
            'company_user_access_id' => $access->id,
            'company_id' => $company->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);
        $this->vehicle($company, $site, 'MID-01', 'mid_suv');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/availability?' . http_build_query([
                'site_id' => $site->id,
                'pickup_at' => '2026-11-01T10:00:00-04:00',
                'due_at' => '2026-11-02T10:00:00-04:00',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_sogebank_transfer_requires_proof_and_an_authorized_approval(): void
    {
        [$user, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.payments.submit',
            'rental.payments.approve',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-02', 'suv');
        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", [
                'payment_kind' => 'security_deposit',
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount' => '250.00',
                'bank_name' => 'Sogebank',
                'bank_reference' => 'SOG-2026-0001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proof_storage_key', 'proof_sha256']);

        $payment = $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", [
                'payment_kind' => 'security_deposit',
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount' => '250.00',
                'bank_name' => 'Sogebank',
                'bank_reference' => 'SOG-2026-0001',
                'proof_storage_key' => 'car-rental/payments/evidence-1.jpg',
                'proof_sha256' => str_repeat('a', 64),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments/{$payment['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $event = AuditEvent::query()->where('event_type', 'car_rental.payment_submitted')->firstOrFail();
        self::assertArrayNotHasKey('bank_reference', $event->metadata);
    }

    public function test_check_out_requires_an_approved_payment_a_held_deposit_and_a_verified_driver_license(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.manage',
            'rental.payments.submit',
            'rental.payments.approve',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-CHECKOUT', 'suv');
        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/check-out", [
                'expected_lock_version' => $reservation['lock_version'],
                'driver_full_name' => 'Jean Pierre',
                'driver_license_number' => 'HT-123456',
                'driver_license_expires_at' => '2027-01-01',
                'driver_license_verified' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $cashRegister = CashRegister::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => 'CAR-01',
            'name' => 'Caisse Car Rental 1',
            'is_active' => true,
        ]);

        $rentalPayment = $this->submitCashPayment($token, $company, $reservation['id'], $cashRegister->id, 'rental', '130.00');
        $this->approvePayment($token, $company, $reservation['id'], $rentalPayment['id']);
        $depositPayment = $this->submitCashPayment($token, $company, $reservation['id'], $cashRegister->id, 'security_deposit', '250.00');
        $this->approvePayment($token, $company, $reservation['id'], $depositPayment['id']);

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/check-out", [
                'expected_lock_version' => $reservation['lock_version'],
                'driver_full_name' => 'Jean Pierre',
                'driver_license_number' => 'HT-123456',
                'driver_license_expires_at' => '2027-01-01',
                'driver_license_verified' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('driver_license_verified');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/check-out", [
                'expected_lock_version' => $reservation['lock_version'],
                'driver_full_name' => 'Jean Pierre',
                'driver_license_number' => 'HT-123456',
                'driver_license_expires_at' => '2027-01-01',
                'driver_license_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'checked_out')
            ->assertJsonPath('data.driver_license_verified', true)
            ->assertJsonMissingPath('data.driver_license_number');

        $this->assertDatabaseHas('car_rental_security_deposits', [
            'company_id' => $company->id,
            'reservation_id' => $reservation['id'],
            'payment_id' => $depositPayment['id'],
            'status' => 'held',
            'currency' => 'USD',
            'amount' => '250.00',
        ]);
        $this->assertDatabaseHas('car_rental_reservations', [
            'id' => $reservation['id'],
            'state' => 'checked_out',
            'driver_full_name' => 'Jean Pierre',
        ]);
    }

    /**
     * @param array<int, string> $permissions
     * @return array{0: User, 1: Company, 2: Site, 3: string, 4: CompanyUserAccess}
     */
    private function context(array $permissions, string $siteScope = 'all'): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::query()->create([
            'code' => 'RENT',
            'legal_name' => 'Clientèle Rent a Car S.A.',
            'display_name' => 'Clientèle Rent a Car',
            'base_currency' => 'USD',
        ]);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'CAP-AERO',
            'name' => 'Bureau Cap-Haïtien',
            'address' => 'Cap-Haïtien',
        ]);
        $access = CompanyUserAccess::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'prepose',
            'site_scope' => $siteScope,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        [, $token] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        return [$user, $company, $site, $token, $access];
    }

    private function vehicle(Company $company, Site $site, string $code, string $category): CarRentalVehicle
    {
        return CarRentalVehicle::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $code,
            'category' => $category,
            'operational_status' => 'available',
            'make' => 'Toyota',
            'model' => 'Test',
            'registration_number' => $code,
            'registration_status' => 'normal',
            'latest_odometer_km' => 100,
            'daily_rate_usd' => '130.00',
            'minimum_security_deposit_usd' => '250.00',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function submitCashPayment(
        string $token,
        Company $company,
        string $reservationId,
        string $cashRegisterId,
        string $kind,
        string $amount,
    ): array {
        return $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservationId}/payments", [
                'payment_kind' => $kind,
                'method' => 'cash',
                'currency' => 'USD',
                'amount' => $amount,
                'cash_register_id' => $cashRegisterId,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function approvePayment(string $token, Company $company, string $reservationId, string $paymentId): void
    {
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservationId}/payments/{$paymentId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    /** @param array<string, mixed> $overrides */
    private function reservationPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer' => [
                'customer_type' => 'individual',
                'display_name' => 'Jean Pierre',
                'email' => 'jean.pierre@example.test',
                'phone' => '+50937000000',
                'group_contact_sharing_consent' => false,
            ],
            'pickup_at' => '2026-11-02T10:00:00-04:00',
            'due_at' => '2026-11-05T10:00:00-04:00',
            'pickup_location_type' => 'site',
            'dropoff_location_type' => 'cap_haitien_airport',
            'currency' => 'USD',
            'daily_rate' => '75.00',
            'kilometer_plan' => 'limited',
            'included_km' => 300,
            'additional_km_rate' => '0.50',
        ], $overrides);
    }

    private function requestFor(string $token, Company $company): self
    {
        return $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id);
    }
}
