<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CompanyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_only_gets_the_selected_authorized_company_and_its_sites(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $companyA = Company::query()->create([
            'code' => 'RENT',
            'legal_name' => 'Clientèle Rent a Car S.A.',
            'display_name' => 'Clientèle Rent a Car',
        ]);
        $companyB = Company::query()->create([
            'code' => 'MARKET',
            'legal_name' => 'Clientèle Market S.A.',
            'display_name' => 'Clientèle Market',
        ]);
        $siteA = Site::query()->create([
            'company_id' => $companyA->id,
            'code' => 'CAP-AERO',
            'name' => 'Bureau aéroport',
            'address' => 'Aéroport du Cap-Haïtien',
        ]);
        Site::query()->create([
            'company_id' => $companyB->id,
            'code' => 'LIMONADE',
            'name' => 'Marché Limonade',
            'address' => 'Limonade',
        ]);
        CompanyUserAccess::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'role_key' => 'prepose',
            'site_scope' => 'all',
            'permissions' => ['rental.read'],
        ]);

        [, $plainToken] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        $this->withToken($plainToken)
            ->getJson('/api/v1/context')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sélectionnez une société autorisée avant de continuer.');

        $this->withToken($plainToken)
            ->withHeader('X-Clientele-Company-Id', $companyB->id)
            ->getJson('/api/v1/context')
            ->assertForbidden();

        $this->withToken($plainToken)
            ->withHeader('X-Clientele-Company-Id', $companyA->id)
            ->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('company.id', $companyA->id)
            ->assertJsonPath('access.role_key', 'prepose')
            ->assertJsonCount(1, 'sites')
            ->assertJsonPath('sites.0.id', $siteA->id);
    }

    public function test_the_company_list_contains_only_explicitly_authorized_companies(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $allowedCompany = Company::query()->create([
            'code' => 'AUTO',
            'legal_name' => 'Clientèle Auto Parts S.A.',
            'display_name' => 'Clientèle Auto Parts',
        ]);
        $otherCompany = Company::query()->create([
            'code' => 'GUEST',
            'legal_name' => 'Clientèle Guest House S.A.',
            'display_name' => 'Clientèle Guest House',
        ]);
        CompanyUserAccess::query()->create([
            'company_id' => $allowedCompany->id,
            'user_id' => $user->id,
            'role_key' => 'superviseur',
            'permissions' => ['inventory.read'],
        ]);

        [, $plainToken] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        $this->withToken($plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.id', $allowedCompany->id)
            ->assertJsonMissing(['id' => $otherCompany->id]);
    }
}
