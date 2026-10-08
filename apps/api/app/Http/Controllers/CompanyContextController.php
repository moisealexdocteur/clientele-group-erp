<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyContextController extends Controller
{
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
            ],
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
