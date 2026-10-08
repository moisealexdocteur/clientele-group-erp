<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CarRentalReservation;
use App\Models\CarRentalVehicle;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\CustomerProfile;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CarRentalVehicleAndCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_manager_can_create_list_and_update_a_vehicle_status(): void
    {
        [, $company, $site, $token] = $this->context([
            'rental.vehicles.read',
            'rental.vehicles.manage',
        ]);

        $vehicle = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', [
                'site_id' => $site->id,
                'code' => 'suv-17',
                'category' => 'suv',
                'make' => 'Toyota',
                'model' => 'RAV4',
                'model_year' => 2024,
                'registration_number' => 'AA-12345',
                'vin' => '1TESTVIN000000017',
                'latest_odometer_km' => 12600,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SUV-17')
            ->assertJsonPath('data.operational_status', 'available')
            ->json('data');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site.id', $site->id);

        $this->requestFor($token, $company)
            ->patchJson("/api/v1/car-rental/vehicles/{$vehicle['id']}/operational-status", [
                'operational_status' => 'garage',
            ])
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'garage');

        $this->assertDatabaseHas('car_rental_vehicles', [
            'id' => $vehicle['id'],
            'operational_status' => 'garage',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'car_rental.vehicle_created',
            'company_id' => $company->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'car_rental.vehicle_status_changed',
            'company_id' => $company->id,
        ]);
    }

    public function test_selected_site_scope_limits_vehicle_and_calendar_data_without_disclosing_customer_information(): void
    {
        [, $company, $siteA, $token, $access] = $this->context([
            'rental.vehicles.read',
            'rental.calendar.read',
        ], 'selected');
        $siteB = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'CAP-GARAGE',
            'name' => 'Garage Cap-Haïtien',
            'address' => 'Cap-Haïtien',
        ]);
        CompanyUserSiteAccess::query()->create([
            'company_user_access_id' => $access->id,
            'company_id' => $company->id,
            'site_id' => $siteA->id,
            'is_active' => true,
        ]);

        $vehicleA = $this->vehicle($company, $siteA, 'SUV-A', 'suv');
        $vehicleB = $this->vehicle($company, $siteB, 'SUV-B', 'suv');
        $this->reservation($company, $siteA, $vehicleA, '00000011', 'reserved');
        $this->reservation($company, $siteB, $vehicleB, '00000012', 'reserved');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SUV-A');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/vehicles?site_id=' . $siteB->id)
            ->assertForbidden();

        $calendar = $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/calendar?' . http_build_query([
                'from' => '2026-11-01T00:00:00-04:00',
                'to' => '2026-11-08T00:00:00-04:00',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'vehicles')
            ->assertJsonPath('data.0.vehicle.code', 'SUV-A')
            ->assertJsonPath('vehicles.0.code', 'SUV-A')
            ->json('data.0');

        self::assertArrayNotHasKey('customer', $calendar);
        self::assertArrayNotHasKey('customer_profile_id', $calendar);
        self::assertArrayNotHasKey('registration_number', $calendar['vehicle']);
        self::assertArrayNotHasKey('vin', $calendar['vehicle']);
    }

    public function test_vehicle_identifiers_are_normalized_and_unique_per_company(): void
    {
        [, $company, $site, $token] = $this->context(['rental.vehicles.manage']);

        $payload = [
            'site_id' => $site->id,
            'code' => 'suv-21',
            'category' => 'suv',
            'registration_number' => 'aa-00991',
            'vin' => '1testvin000000021',
            'latest_odometer_km' => 100,
        ];

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'SUV-21');

        $this->assertDatabaseHas('car_rental_vehicles', [
            'company_id' => $company->id,
            'registration_number' => 'AA-00991',
            'vin' => '1TESTVIN000000021',
        ]);

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', [
                ...$payload,
                'code' => 'SUV-22',
                'registration_number' => 'AA-00991',
                'vin' => '1TESTVIN000000021',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_number', 'vin']);

        [, $otherCompany, $otherSite, $otherToken] = $this->context(['rental.vehicles.manage'], 'all', 'RENT2');
        $this->requestFor($otherToken, $otherCompany)
            ->postJson('/api/v1/car-rental/vehicles', [
                ...$payload,
                'site_id' => $otherSite->id,
            ])
            ->assertCreated();
    }

    public function test_calendar_ignores_cancelled_or_non_overlapping_reservations(): void
    {
        [, $company, $site, $token] = $this->context(['rental.calendar.read']);
        $vehicle = $this->vehicle($company, $site, 'PICK-1', 'pickup');
        $this->reservation($company, $site, $vehicle, '00000021', 'cancelled');
        $this->reservation(
            $company,
            $site,
            $vehicle,
            '00000022',
            'reserved',
            '2026-12-12T10:00:00-05:00',
            '2026-12-14T10:00:00-05:00',
        );

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/calendar?' . http_build_query([
                'from' => '2026-11-01T00:00:00-04:00',
                'to' => '2026-11-08T00:00:00-04:00',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @param array<int, string> $permissions
     * @return array{0: User, 1: Company, 2: Site, 3: string, 4: CompanyUserAccess}
     */
    private function context(array $permissions, string $siteScope = 'all', string $companyCode = 'RENT'): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::query()->create([
            'code' => $companyCode,
            'legal_name' => 'Clientèle Rent a Car S.A.',
            'display_name' => 'Clientèle Rent a Car',
            'base_currency' => 'USD',
        ]);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => $companyCode . '-CAP-AERO',
            'name' => 'Bureau Cap-Haïtien',
            'address' => 'Cap-Haïtien',
        ]);
        $access = CompanyUserAccess::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'superviseur',
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

    private function reservation(
        Company $company,
        Site $site,
        CarRentalVehicle $vehicle,
        string $number,
        string $state,
        string $pickupAt = '2026-11-02T10:00:00-04:00',
        string $dueAt = '2026-11-05T10:00:00-04:00',
    ): CarRentalReservation {
        $customer = CustomerProfile::query()->create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'display_name' => 'Client masqué',
            'is_active' => true,
        ]);

        return CarRentalReservation::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'customer_profile_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'reservation_number' => $number,
            'state' => $state,
            'pickup_at' => $pickupAt,
            'due_at' => $dueAt,
            'pickup_location_type' => 'site',
            'dropoff_location_type' => 'cap_haitien_airport',
            'currency' => 'USD',
            'daily_rate' => '75.00',
            'kilometer_plan' => 'limited',
            'included_km' => 300,
            'additional_km_rate' => '0.50',
        ]);
    }

    private function requestFor(string $token, Company $company): self
    {
        return $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id);
    }
}
