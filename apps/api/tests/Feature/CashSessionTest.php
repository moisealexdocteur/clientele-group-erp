<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CarRentalSecurityDeposit;
use App\Models\CarRentalVehicle;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CashSessionTest extends TestCase
{
    use RefreshDatabase;

    private const SUPERVISOR = [
        'rental.reservations.create',
        'rental.reservations.read',
        'rental.payments.submit',
        'rental.payments.approve',
        'cash.sessions.operate',
        'cash.sessions.approve',
        'cash.reports.read',
    ];

    public function test_cash_requires_an_open_register_and_the_session_tracks_expected_cash(): void
    {
        [$company, $site, $token] = $this->context(self::SUPERVISOR);
        $register = $this->register($company, $site, 'CAR-01');
        $reservation = $this->reservation($token, $company, $site, 'SUV-CASH');

        // Caisse fermée : les espèces sont refusées.
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation}/payments", $this->cash($register->id, 'rental', 'USD', '100.00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_register_id');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('sites.0.cash_registers.0.is_open', false);

        $session = $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '50', 'opening_htg' => '1000'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.totals.USD.opening', '50.00')
            ->assertJsonPath('data.totals.HTG.expected', '1000.00')
            ->json('data');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '0', 'opening_htg' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('register');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/context')
            ->assertJsonPath('sites.0.cash_registers.0.is_open', true);

        $rental = $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation}/payments", $this->cash($register->id, 'rental', 'USD', '390.00'))
            ->assertCreated()
            ->json('data');

        // Un paiement en espèces en attente bloque la clôture.
        $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/sessions/{$session['id']}/close", ['declared_usd' => '440', 'declared_htg' => '1000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session');

        $this->approve($token, $company, $reservation, $rental['id']);
        $deposit = $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation}/payments", $this->cash($register->id, 'security_deposit', 'USD', '250.00'))
            ->assertCreated()
            ->json('data');
        $this->approve($token, $company, $reservation, $deposit['id']);

        $detail = $this->requestFor($token, $company)
            ->getJson("/api/v1/cash/sessions/{$session['id']}")
            ->assertOk()
            ->assertJsonPath('data.totals.USD.in', '640.00')
            ->assertJsonPath('data.totals.USD.expected', '690.00')
            ->assertJsonPath('data.movements.0.kind', 'rental_payment')
            ->assertJsonPath('data.movements.0.receipt_number', '0000 0001')
            ->assertJsonPath('data.movements.1.kind', 'deposit_payment')
            ->json('data');

        self::assertStringNotContainsString('Jean Pierre', json_encode($detail, JSON_THROW_ON_ERROR));

        // Écart sans explication : refusé.
        $closeUrl = "/api/v1/cash/sessions/{$session['id']}/close";
        $this->requestFor($token, $company)
            ->postJson($closeUrl, ['declared_usd' => '685', 'declared_htg' => '1000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variance_note');

        $closed = $this->requestFor($token, $company)
            ->postJson($closeUrl, ['declared_usd' => '685', 'declared_htg' => '1000', 'variance_note' => 'Billet de 5 USD manquant'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.declared.USD', '685.00')
            ->assertJsonPath('data.variance.USD', '-5.00')
            ->assertJsonPath('data.variance.HTG', '0.00')
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.can_review', true)
            ->json('data');

        self::assertSame('closed', $closed['status']);
        self::assertTrue(AuditEvent::query()->where('event_type', 'cash.session_closed_with_variance')->exists());

        $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/sessions/{$session['id']}/review", ['note' => 'Écart retenu sur la paie'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved');

        // Fond proposé à la prochaine ouverture : les montants comptés.
        $this->requestFor($token, $company)
            ->getJson('/api/v1/cash/registers')
            ->assertOk()
            ->assertJsonPath('data.0.session', null)
            ->assertJsonPath('data.0.suggested_opening.USD', '685.00')
            ->assertJsonPath('data.0.suggested_opening.HTG', '1000.00');
    }

    public function test_the_unretained_part_of_a_cash_deposit_leaves_the_register(): void
    {
        [$company, $site, $token] = $this->context([...self::SUPERVISOR, 'rental.deposits.settle']);
        $register = $this->register($company, $site, 'CAR-02');
        $reservation = $this->reservation($token, $company, $site, 'SUV-DEPOT');
        $session = $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '0', 'opening_htg' => '0'])
            ->assertCreated()
            ->json('data.id');

        $deposit = $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation}/payments", $this->cash($register->id, 'security_deposit', 'USD', '250.00'))
            ->assertCreated()
            ->json('data');
        $this->approve($token, $company, $reservation, $deposit['id']);

        $held = CarRentalSecurityDeposit::query()->where('payment_id', $deposit['id'])->firstOrFail();
        app(\App\Support\CashSessionService::class)->recordDepositRefund($company, $held, 205.0, null);

        $this->requestFor($token, $company)
            ->getJson("/api/v1/cash/sessions/{$session}")
            ->assertOk()
            ->assertJsonPath('data.totals.USD.in', '250.00')
            ->assertJsonPath('data.totals.USD.out', '205.00')
            ->assertJsonPath('data.totals.USD.expected', '45.00')
            ->assertJsonPath('data.movements.1.kind', 'deposit_refund')
            ->assertJsonPath('data.movements.1.direction', 'out');
    }

    public function test_an_agent_closes_only_its_own_session_and_cannot_read_the_daily_report(): void
    {
        [$company, $site, $token] = $this->context(self::SUPERVISOR);
        $register = $this->register($company, $site, 'CAR-03');
        $session = $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '20', 'opening_htg' => '0'])
            ->assertCreated()
            ->json('data.id');

        $agent = $this->user($company, ['cash.sessions.operate', 'rental.reservations.read']);

        $this->requestFor($agent, $company)
            ->getJson('/api/v1/cash/registers')
            ->assertOk()
            ->assertJsonPath('data.0.session.can_close', false)
            ->assertJsonPath('permissions.reports', false);

        $this->requestFor($agent, $company)
            ->postJson("/api/v1/cash/sessions/{$session}/close", ['declared_usd' => '20', 'declared_htg' => '0'])
            ->assertForbidden();

        $this->requestFor($agent, $company)
            ->getJson('/api/v1/cash/reports/daily')
            ->assertForbidden();

        $outsider = $this->user($company, ['rental.reservations.read']);
        $this->requestFor($outsider, $company)
            ->getJson('/api/v1/cash/registers')
            ->assertForbidden();

        // Sans écart, la clôture n'attend aucune approbation.
        $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/sessions/{$session}/close", ['declared_usd' => '20', 'declared_htg' => '0'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'none')
            ->assertJsonPath('data.can_review', false);
    }

    public function test_the_daily_report_totals_cash_and_other_payments_within_the_authorized_sites(): void
    {
        [$company, $site, $token] = $this->context([...self::SUPERVISOR, 'rental.payments.credit']);
        $other = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'LIMONADE',
            'name' => 'Bureau Limonade',
            'address' => 'Limonade',
        ]);
        $register = $this->register($company, $site, 'CAR-04');
        $otherRegister = $this->register($company, $other, 'LIM-01');
        $reservation = $this->reservation($token, $company, $site, 'SUV-RAPPORT');

        $session = $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '10', 'opening_htg' => '500'])
            ->assertCreated()
            ->json('data.id');
        $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/registers/{$otherRegister->id}/sessions", ['opening_usd' => '5', 'opening_htg' => '0'])
            ->assertCreated();

        $payment = $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation}/payments", $this->cash($register->id, 'rental', 'USD', '130.00'))
            ->assertCreated()
            ->json('data');
        $this->approve($token, $company, $reservation, $payment['id']);
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation}/payments", [
                'payment_kind' => 'rental',
                'method' => 'credit',
                'currency' => 'USD',
                'amount' => '260.00',
            ])
            ->assertCreated();

        $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/sessions/{$session}/close", ['declared_usd' => '140', 'declared_htg' => '500'])
            ->assertOk();

        $report = $this->requestFor($token, $company)
            ->getJson('/api/v1/cash/reports/daily')
            ->assertOk()
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.totals.USD.opening', '15.00')
            ->assertJsonPath('data.totals.USD.in', '130.00')
            ->assertJsonPath('data.totals.USD.expected', '145.00')
            ->assertJsonPath('data.totals.USD.declared', '140.00')
            ->assertJsonPath('data.totals.USD.variance', '0.00')
            ->assertJsonPath('data.open_sessions', 1)
            ->assertJsonPath('data.movements_by_kind.0.kind', 'rental_payment')
            ->assertJsonPath('data.other_payments.0.method', 'credit')
            ->assertJsonPath('data.other_payments.0.amount', '260.00')
            ->json('data');

        self::assertStringNotContainsString('Jean Pierre', json_encode($report, JSON_THROW_ON_ERROR));

        $this->requestFor($token, $company)
            ->getJson('/api/v1/cash/reports/daily?site_id=' . $site->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.site', 'Bureau Cap-Haïtien');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/cash/reports/daily?date=2020-01-01')
            ->assertOk()
            ->assertJsonCount(0, 'data.sessions')
            ->assertJsonPath('data.totals.USD.expected', '0.00');

        $this->requestFor($token, $company)
            ->postJson('/api/v1/cash/reports/daily/exports', ['date' => $report['date'], 'format' => 'xlsx'])
            ->assertOk();
        self::assertTrue(AuditEvent::query()->where('event_type', 'report.daily_cash_exported')->exists());

        // Un accès limité à une adresse ne voit pas la caisse de l'autre.
        $limited = $this->user($company, self::SUPERVISOR, [$site->id]);
        $this->requestFor($limited, $company)
            ->getJson('/api/v1/cash/reports/daily')
            ->assertOk()
            ->assertJsonCount(1, 'data.sessions');
        $this->requestFor($limited, $company)
            ->getJson('/api/v1/cash/reports/daily?site_id=' . $other->id)
            ->assertForbidden();
    }

    /** @return array{0: Company, 1: Site, 2: string} */
    private function context(array $permissions): array
    {
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

        return [$company, $site, $this->user($company, $permissions)];
    }

    /** @param array<int, string> $siteIds */
    private function user(Company $company, array $permissions, array $siteIds = []): string
    {
        $user = User::factory()->create(['is_active' => true]);
        $access = CompanyUserAccess::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'prepose',
            'site_scope' => $siteIds === [] ? 'all' : 'selected',
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        foreach ($siteIds as $siteId) {
            CompanyUserSiteAccess::query()->create([
                'company_id' => $company->id,
                'company_user_access_id' => $access->id,
                'site_id' => $siteId,
                'is_active' => true,
            ]);
        }

        [, $token] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        return $token;
    }

    private function register(Company $company, Site $site, string $code): CashRegister
    {
        return CashRegister::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $code,
            'name' => 'Caisse ' . $code,
            'is_active' => true,
        ]);
    }

    private function reservation(string $token, Company $company, Site $site, string $code): string
    {
        $vehicle = CarRentalVehicle::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $code,
            'category' => 'suv',
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

        return $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', [
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
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
                'dropoff_location_type' => 'site',
                'currency' => 'USD',
                'daily_rate' => '130.00',
                'kilometer_plan' => 'unlimited',
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @return array<string, string> */
    private function cash(string $registerId, string $kind, string $currency, string $amount): array
    {
        return [
            'payment_kind' => $kind,
            'method' => 'cash',
            'currency' => $currency,
            'amount' => $amount,
            'cash_register_id' => $registerId,
        ];
    }

    private function approve(string $token, Company $company, string $reservation, string $payment): void
    {
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation}/payments/{$payment}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    private function requestFor(string $token, Company $company): self
    {
        return $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id);
    }
}
