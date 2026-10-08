<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CarRentalVehicle;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
            'latest_odometer_km' => 100,
            'is_active' => true,
        ]);
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
