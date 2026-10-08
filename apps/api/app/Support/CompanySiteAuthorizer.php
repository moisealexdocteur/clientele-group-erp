<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Applique la portée de site après le contexte de société. Un accès à une
 * société ne donne pas automatiquement accès à toutes ses adresses.
 */
final class CompanySiteAuthorizer
{
    /**
     * @return Collection<int, string>
     */
    public function activeSiteIdsFor(Company $company, CompanyUserAccess $access): Collection
    {
        return Site::query()
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
            ->pluck('id');
    }

    public function siteFor(Company $company, CompanyUserAccess $access, string $siteId): Site
    {
        $site = Site::query()
            ->where('company_id', $company->id)
            ->whereKey($siteId)
            ->where('is_active', true)
            ->first();

        abort_if($site === null, 404, 'Site actif introuvable pour cette société.');

        if ($access->hasSelectedSiteScope()) {
            $granted = CompanyUserSiteAccess::query()
                ->where('company_user_access_id', $access->id)
                ->where('company_id', $company->id)
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->exists();

            abort_unless($granted, 403, 'Votre accès ne couvre pas cette adresse.');
        }

        return $site;
    }
}
