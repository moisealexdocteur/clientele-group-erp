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
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'address']);

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
            'sites' => $sites,
        ]);
    }
}
