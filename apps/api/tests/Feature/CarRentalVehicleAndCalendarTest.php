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
                'category' => 'suv',
                'make' => 'Toyota',
                'model' => 'RAV4',
                'model_year' => 2024,
                'registration_number' => 'AA-12345',
                'registration_status' => 'normal',
                'vin' => '1TESTVIN000000017',
                'latest_odometer_km' => 12600,
                'daily_rate_usd' => '130.00',
                'minimum_security_deposit_usd' => '250.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'AA-12345')
            ->assertJsonPath('data.registration_number', 'AA-12345')
            ->assertJsonPath('data.registration_status', 'normal')
            ->assertJsonPath('data.operational_status', 'available')
            ->assertJsonPath('data.daily_rate_usd', '130.00')
            ->assertJsonPath('data.minimum_security_deposit_usd', '250.00')
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

        $this->requestFor($token, $company)
            ->patchJson("/api/v1/car-rental/vehicles/{$vehicle['id']}/commercial-terms", [
                'daily_rate_usd' => '150.00',
                'minimum_security_deposit_usd' => '325.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.daily_rate_usd', '150.00')
            ->assertJsonPath('data.minimum_security_deposit_usd', '325.00');

        $this->assertDatabaseHas('car_rental_vehicles', [
            'id' => $vehicle['id'],
            'operational_status' => 'garage',
            'daily_rate_usd' => '150.00',
            'minimum_security_deposit_usd' => '325.00',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'car_rental.vehicle_created',
            'company_id' => $company->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'car_rental.vehicle_status_changed',
            'company_id' => $company->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'car_rental.vehicle_commercial_terms_updated',
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

    public function test_availability_only_returns_vehicles_marked_available(): void
    {
        [, $company, $site, $token] = $this->context(['rental.availability.read']);
        $available = $this->vehicle($company, $site, 'SUV-AVAILABLE', 'suv');
        $washing = $this->vehicle($company, $site, 'SUV-WASHING', 'suv');
        $washing->forceFill(['operational_status' => 'washing'])->save();

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/availability?' . http_build_query([
                'site_id' => $site->id,
                'pickup_at' => '2026-11-02T10:00:00-04:00',
                'due_at' => '2026-11-05T10:00:00-04:00',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id);
    }

    public function test_vehicle_identifiers_are_normalized_and_unique_per_company(): void
    {
        [, $company, $site, $token] = $this->context(['rental.vehicles.manage']);

        $payload = [
            'site_id' => $site->id,
            'category' => 'suv',
            'registration_number' => 'aa-00991',
            'registration_status' => 'normal',
            'vin' => '1testvin000000021',
            'latest_odometer_km' => 100,
            'daily_rate_usd' => '130.00',
            'minimum_security_deposit_usd' => '250.00',
        ];

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'AA-00991');

        $this->assertDatabaseHas('car_rental_vehicles', [
            'company_id' => $company->id,
            'registration_number' => 'AA-00991',
            'vin' => '1TESTVIN000000021',
        ]);

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', [
                ...$payload,
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

    public function test_a_catalog_reference_photo_is_limited_to_the_versioned_public_fleet_images(): void
    {
        [, $company, $site, $token] = $this->context(['rental.vehicles.manage']);

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', [
                'site_id' => $site->id,
                'category' => 'pickup',
                'make' => 'Nissan',
                'model' => 'Frontier',
                'registration_number' => 'AA-85177',
                'registration_status' => 'normal',
                'reference_photo_key' => 'nissan-frontier-aa-85177',
                'latest_odometer_km' => 15420,
                'daily_rate_usd' => '200.00',
                'minimum_security_deposit_usd' => '300.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reference_photo.key', 'nissan-frontier-aa-85177')
            ->assertJsonPath('data.reference_photo.url', '/fleet/nissan-frontier-aa-85177.jpg')
            ->assertJsonPath('data.reference_photo.label', 'Photo de référence - publication Clientèle Group');

        $this->assertDatabaseHas('car_rental_vehicles', [
            'company_id' => $company->id,
            'registration_number' => 'AA-85177',
            'reference_photo_key' => 'nissan-frontier-aa-85177',
        ]);

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', [
                'site_id' => $site->id,
                'category' => 'pickup',
                'registration_number' => 'AA-85178',
                'registration_status' => 'normal',
                'reference_photo_key' => 'image-non-autorisée',
                'latest_odometer_km' => 15421,
                'daily_rate_usd' => '200.00',
                'minimum_security_deposit_usd' => '300.00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_photo_key');
    }

    public function test_a_demonstration_plate_can_be_replaced_by_a_rental_plate_and_remains_in_the_vehicle_history(): void
    {
        [, $company, $site, $token] = $this->context(['rental.vehicles.manage']);

        $vehicle = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/vehicles', [
                'site_id' => $site->id,
                'category' => 'pickup',
                'registration_number' => 'DEMONSTRATION-01',
                'registration_status' => 'demonstration',
                'latest_odometer_km' => 0,
                'daily_rate_usd' => '200.00',
                'minimum_security_deposit_usd' => '300.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'DEMONSTRATION-01')
            ->assertJsonPath('data.registration_status', 'demonstration')
            ->json('data');

        $this->requestFor($token, $company)
            ->patchJson("/api/v1/car-rental/vehicles/{$vehicle['id']}/registration", [
                'registration_number' => 'LO-44556',
                'registration_status' => 'location',
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'LO-44556')
            ->assertJsonPath('data.registration_number', 'LO-44556')
            ->assertJsonPath('data.registration_status', 'location');

        $this->assertDatabaseHas('car_rental_vehicles', [
            'id' => $vehicle['id'],
            'code' => 'LO-44556',
            'registration_number' => 'LO-44556',
            'registration_status' => 'location',
        ]);
        $this->assertDatabaseHas('car_rental_vehicle_registration_events', [
            'vehicle_id' => $vehicle['id'],
            'previous_registration_number' => 'DEMONSTRATION-01',
            'current_registration_number' => 'LO-44556',
            'previous_registration_status' => 'demonstration',
            'current_registration_status' => 'location',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'car_rental.vehicle_registration_changed',
            'company_id' => $company->id,
        ]);
    }

    public function test_a_manager_can_record_oavct_and_tint_permit_expiration_dates(): void
    {
        [, $company, $site, $token] = $this->context(['rental.vehicles.manage']);
        $vehicle = $this->vehicle($company, $site, 'AA-00220', 'suv');
        $expiresAt = now($company->timezone)->addDays(45)->format('Y-m-d');

        $this->requestFor($token, $company)
            ->putJson("/api/v1/car-rental/vehicles/{$vehicle->id}/documents", [
                'documents' => [
                    [
                        'type' => 'registration',
                        'document_number' => 'IMM-220',
                        'issued_at' => '2026-01-01',
                    ],
                    [
                        'type' => 'oavct_insurance',
                        'document_number' => 'OAVCT-220',
                        'expires_at' => $expiresAt,
                    ],
                    [
                        'type' => 'tint_permit',
                        'document_number' => 'TEINTE-220',
                        'expires_at' => $expiresAt,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment([
                'type' => 'oavct_insurance',
                'status' => 'current',
            ]);

        $this->assertDatabaseHas('car_rental_vehicle_documents', [
            'company_id' => $company->id,
            'vehicle_id' => $vehicle->id,
            'document_type' => 'oavct_insurance',
            'document_number' => 'OAVCT-220',
            'expires_at' => $expiresAt . ' 00:00:00',
        ]);

        $this->requestFor($token, $company)
            ->putJson("/api/v1/car-rental/vehicles/{$vehicle->id}/documents", [
                'documents' => [[
                    'type' => 'oavct_insurance',
                    'document_number' => 'OAVCT-220',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');
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
            'registration_number' => $code,
            'registration_status' => 'normal',
            'latest_odometer_km' => 100,
            'daily_rate_usd' => '130.00',
            'minimum_security_deposit_usd' => '250.00',
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
