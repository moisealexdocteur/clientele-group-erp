<?php

namespace Tests\Feature;

use App\Mail\AccountCreatedMail;
use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CompanyUserAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class SystemConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_system_owner_can_create_and_list_global_configuration(): void
    {
        $owner = User::factory()->create([
            'is_active' => true,
            'system_role' => 'owner',
        ]);
        $user = User::factory()->create([
            'is_active' => true,
            'system_role' => 'user',
        ]);
        [, $ownerToken] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));
        [, $userToken] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        $this->withToken($userToken)
            ->getJson('/api/v1/system/configuration/companies')
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette fonction est réservée au propriétaire du système.');

        $response = $this->withToken($ownerToken)
            ->postJson('/api/v1/system/configuration/companies', [
                'code' => 'Clientèle Rent a Car',
                'legal_name' => 'Clientèle Rent a Car S.A.',
                'display_name' => 'Clientèle Rent a Car',
                'base_currency' => 'USD',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CLIENTELE-RENT-A-CAR')
            ->assertJsonPath('data.timezone', 'America/Port-au-Prince')
            ->assertJsonPath('data.timezone_label', 'Cap-Haïtien, Haïti')
            ->assertJsonCount(0, 'data.sites');

        $companyId = $response->json('data.id');

        $this->assertDatabaseHas('company_user_access', [
            'company_id' => $companyId,
            'user_id' => $owner->id,
            'role_key' => 'owner',
            'site_scope' => 'all',
            'is_active' => true,
        ]);
        $this->assertSame(
            ['*'],
            CompanyUserAccess::query()
                ->where('company_id', $companyId)
                ->where('user_id', $owner->id)
                ->firstOrFail()
                ->permissions,
        );

        $this->withToken($ownerToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.id', $companyId);

        $audit = AuditEvent::query()
            ->where('event_type', 'configuration.company_created')
            ->firstOrFail();

        $this->assertSame($companyId, $audit->company_id);
        $this->assertArrayNotHasKey('company_code', $audit->metadata);
        $this->assertSame('USD', $audit->metadata['base_currency']);
    }

    public function test_owner_can_add_a_site_and_its_cash_register_without_cross_company_assignment(): void
    {
        $owner = User::factory()->create([
            'is_active' => true,
            'system_role' => 'owner',
        ]);
        [, $token] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));

        $companyA = $this->createCompany($token, 'AUTO', 'Clientèle Auto Parts');
        $companyB = $this->createCompany($token, 'MARKET', 'Clientèle Market');

        $siteA = $this->withToken($token)
            ->postJson("/api/v1/system/configuration/companies/{$companyA}/sites", [
                'code' => 'CAP-01',
                'name' => 'Magasin principal',
                'address' => 'Adresse à confirmer par Clientèle Group',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CAP-01')
            ->assertJsonPath('data.cash_registers', [])
            ->json('data.id');

        $siteB = $this->withToken($token)
            ->postJson("/api/v1/system/configuration/companies/{$companyB}/sites", [
                'code' => 'LIM-01',
                'name' => 'Magasin Limonade',
                'address' => 'Adresse à confirmer par Clientèle Group',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/system/configuration/companies/{$companyA}/cash-registers", [
                'site_id' => $siteB,
                'code' => 'POS-01',
                'name' => 'Comptoir principal',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');

        $this->withToken($token)
            ->postJson("/api/v1/system/configuration/companies/{$companyA}/cash-registers", [
                'site_id' => $siteA,
                'code' => 'pos-01',
                'name' => 'Comptoir principal',
                'automatic_print_enabled' => true,
                'customer_display_enabled' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'POS-01')
            ->assertJsonPath('data.automatic_print_enabled', true)
            ->assertJsonPath('data.customer_display_enabled', true);

        $configuration = $this->withToken($token)
            ->getJson('/api/v1/system/configuration/companies')
            ->assertOk()
            ->json('data');

        $autoParts = collect($configuration)->firstWhere('id', $companyA);

        $this->assertCount(1, $autoParts['sites']);
        $this->assertSame($siteA, $autoParts['sites'][0]['id']);
        $this->assertCount(1, $autoParts['sites'][0]['cash_registers']);
        $this->assertSame('POS-01', $autoParts['sites'][0]['cash_registers'][0]['code']);

        $siteAudit = AuditEvent::query()
            ->where('event_type', 'configuration.site_created')
            ->where('subject_id', $siteA)
            ->firstOrFail();

        $this->assertArrayNotHasKey('address', $siteAudit->metadata);
    }

    public function test_company_validation_returns_an_actionable_french_message(): void
    {
        $owner = User::factory()->create([
            'is_active' => true,
            'system_role' => 'owner',
        ]);
        [, $token] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));

        $this->withToken($token)
            ->postJson('/api/v1/system/configuration/companies', [
                'code' => '---',
                'legal_name' => 'Clientèle Test S.A.',
                'display_name' => 'Clientèle Test',
                'base_currency' => 'HTG',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', 'Saisissez un code de société.');
    }

    public function test_owner_can_create_a_real_car_rental_user_with_a_limited_site_scope(): void
    {
        Mail::fake();

        $owner = User::factory()->create([
            'is_active' => true,
            'system_role' => 'owner',
        ]);
        [, $ownerToken] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));
        $companyId = $this->createCompany($ownerToken, 'RENTAL', 'Clientèle Rent a Car');

        $siteA = $this->withToken($ownerToken)
            ->postJson("/api/v1/system/configuration/companies/{$companyId}/sites", [
                'code' => 'CAP-AERO',
                'name' => 'Bureau aéroport',
                'address' => 'Cap-Haïtien',
            ])
            ->assertCreated()
            ->json('data.id');
        $siteB = $this->withToken($ownerToken)
            ->postJson("/api/v1/system/configuration/companies/{$companyId}/sites", [
                'code' => 'CAP-VILLE',
                'name' => 'Bureau ville',
                'address' => 'Cap-Haïtien',
            ])
            ->assertCreated()
            ->json('data.id');

        $created = $this->withToken($ownerToken)
            ->postJson("/api/v1/system/configuration/companies/{$companyId}/users", [
                'name' => 'Agent test Car Rental',
                'email' => 'agent.test.car-rental@example.test',
                'password' => 'MotDePasse!2026',
                'password_confirmation' => 'MotDePasse!2026',
                'role_key' => 'car_rental_agent',
                'site_scope' => 'selected',
                'site_ids' => [$siteA],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Agent test Car Rental')
            ->assertJsonPath('data.email', 'agent.test.car-rental@example.test')
            ->assertJsonPath('data.role_key', 'car_rental_agent')
            ->assertJsonPath('data.site_scope', 'selected')
            ->assertJsonPath('notification.sent', true)
            ->assertJsonCount(1, 'data.sites')
            ->assertJsonPath('data.sites.0.id', $siteA)
            ->json('data');

        $this->assertDatabaseHas('users', [
            'id' => $created['user_id'],
            'email' => 'agent.test.car-rental@example.test',
            'is_active' => true,
            'system_role' => 'user',
            'two_factor_email_enabled' => true,
        ]);
        $this->assertDatabaseHas('company_user_access', [
            'id' => $created['id'],
            'company_id' => $companyId,
            'user_id' => $created['user_id'],
            'role_key' => 'car_rental_agent',
            'site_scope' => 'selected',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('company_user_site_access', [
            'company_user_access_id' => $created['id'],
            'company_id' => $companyId,
            'site_id' => $siteA,
            'is_active' => true,
        ]);

        Mail::assertSent(AccountCreatedMail::class, function (AccountCreatedMail $mail): bool {
            return $mail->recipientName === 'Agent test Car Rental'
                && $mail->companyName === 'Clientèle Rent a Car'
                && $mail->roleLabel === 'Agent de location';
        });

        $this->withToken($ownerToken)
            ->getJson("/api/v1/system/configuration/companies/{$companyId}/users")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $created['id'],
                'email' => 'agent.test.car-rental@example.test',
            ]);

        $employee = User::query()->findOrFail($created['user_id']);
        [, $employeeToken] = ApiAccessToken::issueFor($employee, Request::create('/api/v1/auth/login', 'POST'));
        $this->withToken($employeeToken)
            ->withHeader('X-Clientele-Company-Id', $companyId)
            ->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonCount(1, 'sites')
            ->assertJsonPath('sites.0.id', $siteA)
            ->assertJsonMissing(['id' => $siteB]);

        $audit = AuditEvent::query()
            ->where('event_type', 'configuration.company_user_created')
            ->where('subject_id', $created['user_id'])
            ->firstOrFail();
        $this->assertArrayNotHasKey('email', $audit->metadata);
        $this->assertSame('car_rental_agent', $audit->metadata['role_key']);
    }

    public function test_owner_can_manage_then_permanently_delete_an_unshared_company_user(): void
    {
        Mail::fake();

        $owner = User::factory()->create([
            'is_active' => true,
            'system_role' => 'owner',
        ]);
        [, $ownerToken] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));
        $companyId = $this->createCompany($ownerToken, 'RENT-MANAGE', 'Clientèle Rent a Car');

        $created = $this->withToken($ownerToken)
            ->postJson("/api/v1/system/configuration/companies/{$companyId}/users", [
                'name' => 'Utilisateur à gérer',
                'email' => 'utilisateur.a.gerer@example.test',
                'password' => 'MotDePasse!2026',
                'password_confirmation' => 'MotDePasse!2026',
                'role_key' => 'car_rental_agent',
                'site_scope' => 'all',
            ])
            ->assertCreated()
            ->assertJsonPath('data.can_delete_permanently', true)
            ->json('data');

        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}/users/{$created['id']}", [
                'name' => 'Utilisateur mis à jour',
                'email' => 'utilisateur.a.gerer@example.test',
                'role_key' => 'car_rental_fleet',
                'site_scope' => 'all',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Utilisateur mis à jour')
            ->assertJsonPath('data.role_key', 'car_rental_fleet');

        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}/users/{$created['id']}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}/users/{$created['id']}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $employee = User::query()->findOrFail($created['user_id']);
        [, $employeeToken] = ApiAccessToken::issueFor($employee, Request::create('/api/v1/auth/login', 'POST'));

        $this->withToken($ownerToken)
            ->postJson("/api/v1/system/configuration/companies/{$companyId}/users/{$created['id']}/reset-password", [
                'password' => 'NouveauMotDePasse!2026',
                'password_confirmation' => 'NouveauMotDePasse!2026',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Mot de passe réinitialisé. Les sessions existantes ont été fermées. Un code par courriel sera demandé à la prochaine connexion.');

        $this->assertDatabaseHas('api_access_tokens', [
            'token_hash' => hash('sha256', $employeeToken),
        ]);

        $this->withToken($ownerToken)
            ->deleteJson("/api/v1/system/configuration/companies/{$companyId}/users/{$created['id']}", [
                'confirmation_email' => 'utilisateur.a.gerer@example.test',
            ])
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $created['user_id']]);
        $this->assertDatabaseMissing('company_user_access', ['id' => $created['id']]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'configuration.company_user_deleted',
            'subject_id' => $created['user_id'],
        ]);
    }

    public function test_the_owner_records_the_legal_identity_printed_on_rental_contracts(): void
    {
        $owner = User::factory()->create(['is_active' => true, 'system_role' => 'owner']);
        [, $ownerToken] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));
        $companyId = $this->createCompany($ownerToken, 'RENT-LEGAL', 'Clientèle Rent a Car');

        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}", [
                'legal_name' => 'Clientèle Rent A Car',
                'display_name' => 'Clientèle Rent a Car',
                'legal_representative' => 'Représentant de test',
                'tax_identification_number' => '000-000-000-0',
                'legal_address' => 'Adresse de test, Route Nationale 6',
                'phone_numbers' => '(+509) 0000-0000',
            ])
            ->assertOk()
            ->assertJsonPath('data.legal_representative', 'Représentant de test')
            ->assertJsonPath('data.tax_identification_number', '000-000-000-0');

        $this->withToken($ownerToken)
            ->withHeader('X-Clientele-Company-Id', $companyId)
            ->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('company.legal.representative', 'Représentant de test')
            ->assertJsonPath('company.legal.address', 'Adresse de test, Route Nationale 6');

        $event = AuditEvent::query()->where('event_type', 'configuration.company_updated')->firstOrFail();
        self::assertContains('tax_identification_number', $event->metadata['changed']);
    }

    public function test_the_owner_records_the_rental_contract_terms_without_losing_them_on_identity_updates(): void
    {
        $owner = User::factory()->create(['is_active' => true, 'system_role' => 'owner']);
        [, $ownerToken] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));
        $companyId = $this->createCompany($ownerToken, 'RENT-TERMS', 'Clientèle Rent a Car');
        $identity = [
            'legal_name' => 'Clientèle Rent A Car',
            'display_name' => 'Clientèle Rent a Car',
        ];

        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}", [
                ...$identity,
                'rental_contract_terms' => "Article 1 - Objet\nTexte de test.",
            ])
            ->assertOk()
            ->assertJsonPath('data.rental_contract_terms', "Article 1 - Objet\nTexte de test.");

        // Une mise à jour de l'identité sans les conditions ne les efface pas.
        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}", [
                ...$identity,
                'phone_numbers' => '(+509) 0000-0000',
            ])
            ->assertOk()
            ->assertJsonPath('data.rental_contract_terms', "Article 1 - Objet\nTexte de test.");

        $this->withToken($ownerToken)
            ->withHeader('X-Clientele-Company-Id', $companyId)
            ->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('company.legal.rental_contract_terms', "Article 1 - Objet\nTexte de test.");
    }

    public function test_the_owner_designates_who_may_set_the_group_exchange_rate(): void
    {
        $owner = User::factory()->create(['is_active' => true, 'system_role' => 'owner']);
        [, $ownerToken] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));
        $companyId = $this->createCompany($ownerToken, 'RENT-RATE', 'Clientèle Rent a Car');
        $employee = User::factory()->create(['is_active' => true]);
        $access = CompanyUserAccess::query()->create([
            'company_id' => $companyId,
            'user_id' => $employee->id,
            'role_key' => 'car_rental_administrator',
            'site_scope' => 'all',
            'permissions' => ['rental.reservations.read'],
            'is_active' => true,
        ]);
        [, $employeeToken] = ApiAccessToken::issueFor($employee, Request::create('/api/v1/auth/login', 'POST'));

        $this->withToken($employeeToken)
            ->postJson('/api/v1/exchange-rates', ['rate_htg_per_usd' => 131])
            ->assertForbidden();

        $this->withToken($ownerToken)
            ->postJson('/api/v1/exchange-rates', ['rate_htg_per_usd' => 130.5])
            ->assertCreated();

        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}/users/{$access->id}/exchange-rate-access", ['allowed' => true])
            ->assertOk()
            ->assertJsonPath('data.can_manage_exchange_rates', true);

        $this->withToken($employeeToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.can_manage_exchange_rates', true);

        $this->withToken($employeeToken)
            ->postJson('/api/v1/exchange-rates', ['rate_htg_per_usd' => 131])
            ->assertCreated();

        $this->withToken($employeeToken)
            ->getJson('/api/v1/exchange-rates')
            ->assertOk()
            ->assertJsonPath('current.rate_htg_per_usd', '131.0000')
            ->assertJsonPath('can_manage', true);

        $this->withToken($ownerToken)
            ->patchJson("/api/v1/system/configuration/companies/{$companyId}/users/{$access->id}/exchange-rate-access", ['allowed' => false])
            ->assertOk()
            ->assertJsonPath('data.can_manage_exchange_rates', false);

        self::assertTrue(AuditEvent::query()->where('event_type', 'configuration.exchange_rate_access_revoked')->exists());
    }

    private function createCompany(string $token, string $code, string $name): string
    {
        return $this->withToken($token)
            ->postJson('/api/v1/system/configuration/companies', [
                'code' => $code,
                'legal_name' => $name.' S.A.',
                'display_name' => $name,
                'base_currency' => 'HTG',
            ])
            ->assertCreated()
            ->json('data.id');
    }
}
