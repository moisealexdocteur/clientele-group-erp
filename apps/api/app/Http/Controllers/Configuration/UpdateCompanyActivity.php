<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\RoleProfiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Activité d'une société (Car Rental ou Auto Parts et motocyclettes),
 * propriétaire seulement. Elle choisit les écrans et les profils proposés.
 * Le changement est refusé tant qu'un utilisateur actif garde un profil de
 * l'ancienne activité : ces comptes doivent d'abord être désactivés.
 */
final class UpdateCompanyActivity extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'activity' => ['required', Rule::in(Company::ACTIVITIES)],
        ], [
            'activity.required' => 'Sélectionnez l’activité de la société.',
            'activity.in' => 'Sélectionnez Car Rental ou Auto Parts et motocyclettes.',
        ]);

        $previous = $company->activity;

        if ($previous === $data['activity']) {
            return response()->json(['data' => $this->payload($company)]);
        }

        $this->companyContext->within($company->id, function () use ($company, $data, $previous, $request): void {
            $mismatched = CompanyUserAccess::query()
                ->where('company_id', $company->id)
                ->where('role_key', '!=', 'owner')
                ->where('is_active', true)
                ->whereNotIn('role_key', RoleProfiles::keysFor($data['activity']))
                ->count();

            if ($mismatched > 0) {
                throw ValidationException::withMessages([
                    'activity' => sprintf(
                        '%d utilisateur%s actif%s %s un profil de l’activité actuelle. Désactivez ces comptes avant de changer d’activité.',
                        $mismatched,
                        $mismatched > 1 ? 's' : '',
                        $mismatched > 1 ? 's' : '',
                        $mismatched > 1 ? 'ont' : 'a',
                    ),
                ]);
            }

            $company->forceFill(['activity' => $data['activity']])->save();

            $this->audit->record(
                eventType: 'configuration.company_activity_changed',
                companyId: $company->id,
                actorId: $request->user()?->id,
                actorType: 'USER',
                subjectType: Company::class,
                subjectId: $company->id,
                metadata: ['from' => $previous, 'to' => $data['activity']],
            );
        });

        return response()->json(['data' => $this->payload($company->refresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(Company $company): array
    {
        return [
            'id' => $company->id,
            'activity' => $company->activity,
            'role_options' => RoleProfiles::optionsFor($company->activity),
        ];
    }
}
