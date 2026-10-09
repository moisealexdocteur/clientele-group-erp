<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Support\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyContextController extends Controller
{
    public function __construct(
        private readonly ExchangeRateService $rates,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('clientele.company');
        /** @var CompanyUserAccess $access */
        $access = $request->attributes->get('clientele.company_access');

        $sites = Site::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->when(
                $access->hasSelectedSiteScope(),
                static function ($query) use ($access, $company): void {
                    $query->whereIn(
                        'id',
                        CompanyUserSiteAccess::query()
                            ->where('company_user_access_id', $access->id)
                            ->where('company_id', $company->id)
                            ->where('is_active', true)
                            ->pluck('site_id'),
                    );
                },
            )
            ->with(['cashRegisters' => static fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'company_id', 'code', 'name', 'address']);

        return response()->json([
            'company' => [
                'id' => $company->id,
                'code' => $company->code,
                'name' => $company->display_name,
                'timezone' => $company->timezone,
                'timezone_label' => $company->timezone_display_name,
                'base_currency' => $company->base_currency,
                // Identité du loueur imprimée sur le contrat de location.
                'legal' => [
                    'name' => $company->legal_name,
                    'representative' => $company->legal_representative,
                    'tax_identification_number' => $company->tax_identification_number,
                    'address' => $company->legal_address,
                    'phone_numbers' => $company->phone_numbers,
                    'rental_contract_terms' => $company->rental_contract_terms,
                ],
            ],
            // Taux HTG/USD du groupe en vigueur, affiché dans l'application.
            'exchange_rate' => $this->rates->payload($this->rates->current()),
            'access' => [
                'role_key' => $access->role_key,
                'site_scope' => $access->site_scope,
                'permissions' => $access->permissions,
            ],
            'sites' => $sites->map(static fn (Site $site): array => [
                'id' => $site->id,
                'code' => $site->code,
                'name' => $site->name,
                'address' => $site->address,
                'cash_registers' => $site->cashRegisters
                    ->map(static fn ($register): array => [
                        'id' => $register->id,
                        'code' => $register->code,
                        'name' => $register->name,
                        'is_active' => $register->is_active,
                    ])
                    ->values(),
            ])->values(),
        ]);
    }
}
