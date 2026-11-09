<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Mail\AccountCreatedMail;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\RoleProfiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Donne à un compte existant (employé d'une autre société du groupe) un accès
 * à cette société, avec un profil et des adresses propres à cette société.
 * Le mot de passe, le code de sécurité et les données personnelles du compte
 * ne changent pas. Les accès aux autres sociétés restent inchangés.
 * Propriétaire seulement, journalisé.
 */
final class AttachExistingUser extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'role_key' => ['required', Rule::in(RoleProfiles::keysFor($company->activity))],
            'site_scope' => ['required', Rule::in(['all', 'selected'])],
            'site_ids' => ['nullable', 'array', 'max:100'],
            'site_ids.*' => ['uuid', 'distinct'],
        ], [
            'user_id.required' => 'Sélectionnez le compte à ajouter.',
            'user_id.uuid' => 'Sélectionnez le compte à ajouter.',
            'role_key.required' => 'Sélectionnez un profil.',
            'role_key.in' => 'Sélectionnez un profil prévu pour cette société.',
            'site_scope.required' => 'Indiquez les adresses autorisées.',
            'site_scope.in' => 'Indiquez les adresses autorisées.',
        ]);

        $user = User::query()->whereKey($data['user_id'])->first();

        if ($user === null || ! $user->is_active || $user->system_role === 'owner') {
            throw ValidationException::withMessages([
                'user_id' => 'Ce compte ne peut pas être ajouté. Il est désactivé ou c’est le compte propriétaire.',
            ]);
        }

        $siteIds = collect($data['site_ids'] ?? [])->filter()->unique()->values();

        if ($data['site_scope'] === 'selected' && $siteIds->isEmpty()) {
            throw ValidationException::withMessages([
                'site_ids' => 'Sélectionnez au moins une adresse pour un accès limité.',
            ]);
        }

        $owner = $request->user();

        $access = $this->companyContext->within($company->id, function () use ($company, $data, $user, $siteIds, $owner): CompanyUserAccess {
            return DB::transaction(function () use ($company, $data, $user, $siteIds, $owner): CompanyUserAccess {
                $already = CompanyUserAccess::query()
                    ->where('company_id', $company->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($already !== null) {
                    throw ValidationException::withMessages([
                        'user_id' => $already->is_active
                            ? 'Ce compte a déjà accès à cette société.'
                            : 'Ce compte a déjà un accès désactivé dans cette société. Ouvrez sa fiche et réactivez-le.',
                    ]);
                }

                if ($data['site_scope'] === 'selected') {
                    $activeSites = Site::query()
                        ->where('company_id', $company->id)
                        ->where('is_active', true)
                        ->whereIn('id', $siteIds)
                        ->count();

                    if ($activeSites !== $siteIds->count()) {
                        throw ValidationException::withMessages([
                            'site_ids' => 'Sélectionnez uniquement des adresses actives de cette société.',
                        ]);
                    }
                }

                $access = CompanyUserAccess::query()->create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role_key' => $data['role_key'],
                    'site_scope' => $data['site_scope'],
                    'permissions' => RoleProfiles::permissions($data['role_key']),
                    'is_active' => true,
                ]);

                if ($data['site_scope'] === 'selected') {
                    foreach ($siteIds as $siteId) {
                        CompanyUserSiteAccess::query()->create([
                            'company_user_access_id' => $access->id,
                            'company_id' => $company->id,
                            'site_id' => $siteId,
                            'is_active' => true,
                        ]);
                    }
                }

                $this->audit->record(
                    eventType: 'configuration.company_user_attached',
                    companyId: $company->id,
                    actorId: $owner?->id,
                    actorType: 'USER',
                    subjectType: User::class,
                    subjectId: $user->id,
                    metadata: [
                        'role_key' => $access->role_key,
                        'site_scope' => $access->site_scope,
                        'site_count' => $siteIds->count(),
                    ],
                );

                return $access;
            });
        });

        $sent = $this->notify($user, $company, $access, $owner?->id);

        return response()->json([
            'data' => [
                'id' => $access->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_key' => $access->role_key,
                'site_scope' => $access->site_scope,
            ],
            'notification' => ['sent' => $sent],
        ], 201);
    }

    private function notify(User $user, Company $company, CompanyUserAccess $access, ?string $ownerId): bool
    {
        try {
            Mail::to($user->email, $user->name)->send(new AccountCreatedMail(
                $user->name,
                $company->display_name,
                RoleProfiles::label($access->role_key),
                existingAccount: true,
            ));
            $event = 'configuration.company_user_creation_notification_sent';
            $sent = true;
        } catch (Throwable) {
            $event = 'configuration.company_user_creation_notification_failed';
            $sent = false;
        }

        $this->companyContext->within($company->id, fn () => $this->audit->record(
            eventType: $event,
            companyId: $company->id,
            actorId: $ownerId,
            actorType: 'USER',
            subjectType: User::class,
            subjectId: $user->id,
            metadata: ['role_key' => $access->role_key, 'existing_account' => true],
        ));

        return $sent;
    }
}
