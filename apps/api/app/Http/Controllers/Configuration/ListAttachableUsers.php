<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;

/**
 * Comptes existants qui peuvent recevoir un accès à cette société : comptes
 * actifs, hors propriétaire du système, sans accès dans cette société.
 * Seuls le nom et le courriel sont montrés ; les autres sociétés du compte
 * ne sont pas révélées. Propriétaire seulement.
 */
final class ListAttachableUsers extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext)
    {
    }

    public function __invoke(Company $company): JsonResponse
    {
        return $this->companyContext->within($company->id, function () use ($company): JsonResponse {
            $existing = CompanyUserAccess::query()
                ->where('company_id', $company->id)
                ->pluck('user_id')
                ->all();

            $users = User::query()
                ->where('is_active', true)
                ->where('system_role', '!=', 'owner')
                ->whereNotIn('id', $existing)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'email']);

            return response()->json([
                'data' => $users->map(static fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values(),
            ]);
        });
    }
}
