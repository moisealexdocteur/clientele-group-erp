<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CompanyUserAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
                'code' => 'rent',
                'legal_name' => 'Clientèle Rent a Car S.A.',
                'display_name' => 'Clientèle Rent a Car',
                'base_currency' => 'USD',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'RENT')
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
        $this->assertSame([], $audit->metadata);
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
