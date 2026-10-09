<?php

namespace App\Http\Controllers\CarRental\Concerns;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use Illuminate\Http\Request;

/** Société et accès résolus par le middleware de contexte de société. */
trait ResolvesCompanyAccess
{
    protected function company(Request $request): Company
    {
        $company = $request->attributes->get('clientele.company');
        abort_unless($company instanceof Company, 500, 'Contexte de société manquant.');

        return $company;
    }

    protected function access(Request $request): CompanyUserAccess
    {
        $access = $request->attributes->get('clientele.company_access');
        abort_unless($access instanceof CompanyUserAccess, 500, 'Contexte d’accès manquant.');

        return $access;
    }
}
