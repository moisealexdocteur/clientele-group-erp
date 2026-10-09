<?php

namespace App\Http\Controllers;

use App\Mail\AccountCreatedMail;
use App\Models\ApiAccessToken;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Models\User;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\Money;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Réglages réservés au propriétaire du système. Aucune société, adresse ou
 * caisse n'est créée implicitement : chaque enregistrement est une action
 * explicite et journalisée.
 */
final class SystemConfigurationController extends Controller
{
    /** @var array<string, array{permissions: array<int, string>}> */
    private const CAR_RENTAL_ROLE_PROFILES = [
        'car_rental_administrator' => [
            'permissions' => [
                'rental.availability.read',
                'rental.reservations.create',
                'rental.reservations.read',
                'rental.reservations.manage',
                'rental.vehicles.read',
                'rental.vehicles.manage',
                'rental.calendar.read',
                'rental.payments.submit',
                'rental.payments.approve',
                'rental.reservations.override_rate',
                'rental.payments.credit',
                'rental.documents.sensitive',
                'rental.deposits.settle',
                'rental.invoices.issue',
            ],
        ],
        'car_rental_agent' => [
            'permissions' => [
                'rental.availability.read',
                'rental.reservations.create',
                'rental.reservations.read',
                'rental.reservations.manage',
                'rental.vehicles.read',
                'rental.calendar.read',
                'rental.payments.submit',
                'rental.invoices.issue',
            ],
        ],
        'car_rental_fleet' => [
            'permissions' => [
                'rental.vehicles.read',
                'rental.vehicles.manage',
                'rental.calendar.read',
            ],
        ],
    ];

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditLogger $audit,
    ) {
    }

    public function companies(): JsonResponse
    {
        $companies = Company::query()
            ->orderBy('display_name')
            ->get();

        return response()->json([
            'data' => $companies
                ->map(fn (Company $company): array => $this->companyContext->within(
                    $company->id,
                    function () use ($company): array {
                        $company->setRelation(
                            'sites',
                            Site::query()
                                ->where('company_id', $company->id)
                                ->orderBy('name')
                                ->with([
                                    'cashRegisters' => static fn ($registers) => $registers
                                        ->orderBy('name'),
                                ])
                                ->get(),
                        );

                        return $this->companyPayload($company);
                    },
                ))
                ->values(),
        ]);
    }

    public function storeCompany(Request $request): JsonResponse
    {
        $request->merge([
            'code' => $this->canonicalCode($request->input('code')),
        ]);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:32',
                'regex:/\A[A-Z0-9][A-Z0-9_-]{1,31}\z/',
                Rule::unique('companies', 'code'),
            ],
            'legal_name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'base_currency' => ['required', Rule::in(['HTG', 'USD'])],
        ], $this->companyValidationMessages());

        $owner = $this->owner($request);

        $company = DB::transaction(function () use ($data, $owner): Company {
            $company = Company::query()->create([
                'code' => $data['code'],
                'legal_name' => trim($data['legal_name']),
                'display_name' => trim($data['display_name']),
                'base_currency' => $data['base_currency'],
                'timezone' => 'America/Port-au-Prince',
                'timezone_display_name' => 'Cap-Haïtien, Haïti',
                'locale' => 'fr-HT',
                'is_active' => true,
            ]);

            $this->companyContext->within($company->id, function () use ($company, $owner): void {
                CompanyUserAccess::query()->create([
                    'company_id' => $company->id,
                    'user_id' => $owner->id,
                    'role_key' => 'owner',
                    'site_scope' => 'all',
                    'permissions' => ['*'],
                    'is_active' => true,
                ]);

                $this->audit->record(
                    eventType: 'configuration.company_created',
                    companyId: $company->id,
                    actorId: $owner->id,
                    actorType: 'USER',
                    subjectType: Company::class,
                    subjectId: $company->id,
                    metadata: [
                        'company_code' => $company->code,
                        'base_currency' => $company->base_currency,
                    ],
                );
            });

            return $company;
        });

        return response()->json([
            'data' => $this->companyPayload($company->setRelation('sites', collect())),
        ], 201);
    }

    /**
     * Identité légale du loueur, imprimée sur les contrats : nom affiché,
     * représentant, NIF, adresse et téléphones. Ces données sont saisies par
     * le propriétaire et ne sont jamais versionnées dans le dépôt.
     */
    public function updateCompany(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'legal_representative' => ['nullable', 'string', 'max:160'],
            'tax_identification_number' => ['nullable', 'string', 'max:64'],
            'legal_address' => ['nullable', 'string', 'max:1000'],
            'phone_numbers' => ['nullable', 'string', 'max:160'],
            'roadside_assistance_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'rental_contract_terms' => ['sometimes', 'nullable', 'string', 'max:40000'],
            'rental_airport_fee_usd' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999', new DecimalAmount()],
            'rental_cleaning_fee_usd' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999', new DecimalAmount()],
        ], $this->companyValidationMessages());

        $owner = $this->owner($request);
        // Les conditions du contrat ne sont modifiées que si elles sont envoyées.
        $termsProvided = array_key_exists('rental_contract_terms', $data);

        return $this->companyContext->within($company->id, function () use ($company, $data, $owner, $termsProvided): JsonResponse {
            if (array_key_exists('roadside_assistance_phone', $data)) {
                $company->forceFill([
                    'roadside_assistance_phone' => $this->trimmedOrNull($data['roadside_assistance_phone'] ?? null),
                ]);
            }

            // Frais de service Car Rental : réglés ici seulement, jamais dans un écran métier.
            foreach (['rental_airport_fee_usd', 'rental_cleaning_fee_usd'] as $fee) {
                if (array_key_exists($fee, $data)) {
                    $company->forceFill([$fee => Money::normalize((string) $data[$fee])]);
                }
            }

            if ($termsProvided) {
                $company->forceFill([
                    'rental_contract_terms' => $this->trimmedOrNull($data['rental_contract_terms'] ?? null),
                ]);
            }
            $company->forceFill([
                'legal_name' => trim($data['legal_name']),
                'display_name' => trim($data['display_name']),
                'legal_representative' => $this->trimmedOrNull($data['legal_representative'] ?? null),
                'tax_identification_number' => $this->trimmedOrNull($data['tax_identification_number'] ?? null),
                'legal_address' => $this->trimmedOrNull($data['legal_address'] ?? null),
                'phone_numbers' => $this->trimmedOrNull($data['phone_numbers'] ?? null),
            ]);
            $changed = array_keys($company->getDirty());
            $company->save();

            if ($changed !== []) {
                $this->audit->record(
                    eventType: 'configuration.company_updated',
                    companyId: $company->id,
                    actorId: $owner->id,
                    actorType: 'USER',
                    subjectType: Company::class,
                    subjectId: $company->id,
                    metadata: ['changed' => $changed],
                );
            }

            return response()->json([
                'data' => $this->companyPayload($company->load('sites.cashRegisters')),
            ]);
        });
    }

    private function trimmedOrNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    public function storeSite(Request $request, Company $company): JsonResponse
    {
        return $this->companyContext->within($company->id, function () use ($request, $company): JsonResponse {
            $request->merge([
                'code' => $this->canonicalCode($request->input('code')),
            ]);

            $data = $request->validate([
                'code' => [
                    'required',
                    'string',
                    'max:32',
                    'regex:/\A[A-Z0-9][A-Z0-9_-]{1,31}\z/',
                    Rule::unique('sites', 'code')
                        ->where(fn ($query) => $query->where('company_id', $company->id)),
                ],
                'name' => ['required', 'string', 'max:255'],
                'address' => ['required', 'string', 'max:1000'],
            ], $this->siteValidationMessages());

            $site = Site::query()->create([
                'company_id' => $company->id,
                'code' => $data['code'],
                'name' => trim($data['name']),
                'address' => trim($data['address']),
                'is_active' => true,
            ]);

            $actor = $this->owner($request);
            $this->audit->record(
                eventType: 'configuration.site_created',
                companyId: $company->id,
                actorId: $actor->id,
                actorType: 'USER',
                subjectType: Site::class,
                subjectId: $site->id,
                metadata: [
                    'site_code' => $site->code,
                ],
            );

            return response()->json([
                'data' => $this->sitePayload($site->load('cashRegisters')),
            ], 201);
        });
    }

    public function storeCashRegister(Request $request, Company $company): JsonResponse
    {
        return $this->companyContext->within($company->id, function () use ($request, $company): JsonResponse {
            $request->merge([
                'code' => $this->canonicalCode($request->input('code')),
            ]);

            $data = $request->validate([
                'site_id' => ['required', 'uuid'],
                'code' => [
                    'required',
                    'string',
                    'max:32',
                    'regex:/\A[A-Z0-9][A-Z0-9_-]{1,31}\z/',
                    Rule::unique('cash_registers', 'code')
                        ->where(fn ($query) => $query->where('company_id', $company->id)),
                ],
                'name' => ['required', 'string', 'max:255'],
                'automatic_print_enabled' => ['sometimes', 'boolean'],
                'customer_display_enabled' => ['sometimes', 'boolean'],
            ], $this->cashRegisterValidationMessages());

            $site = Site::query()
                ->where('company_id', $company->id)
                ->whereKey($data['site_id'])
                ->where('is_active', true)
                ->first();

            if ($site === null) {
                throw ValidationException::withMessages([
                    'site_id' => 'Sélectionnez une adresse active de cette société.',
                ]);
            }

            $register = CashRegister::query()->create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'code' => $data['code'],
                'name' => trim($data['name']),
                'automatic_print_enabled' => $data['automatic_print_enabled'] ?? false,
                'customer_display_enabled' => $data['customer_display_enabled'] ?? false,
                'is_active' => true,
            ]);

            $actor = $this->owner($request);
            $this->audit->record(
                eventType: 'configuration.cash_register_created',
                companyId: $company->id,
                actorId: $actor->id,
                actorType: 'USER',
                subjectType: CashRegister::class,
                subjectId: $register->id,
                metadata: [
                    'cash_register_code' => $register->code,
                    'site_id' => $site->id,
                    'automatic_print_enabled' => $register->automatic_print_enabled,
                    'customer_display_enabled' => $register->customer_display_enabled,
                ],
            );

            return response()->json([
                'data' => $this->cashRegisterPayload($register),
            ], 201);
        });
    }

    public function companyUsers(Company $company): JsonResponse
    {
        return $this->companyContext->within($company->id, function () use ($company): JsonResponse {
            $accesses = CompanyUserAccess::query()
                ->where('company_id', $company->id)
                ->with([
                    'user',
                    'siteGrants' => static fn ($siteGrants) => $siteGrants
                        ->where('is_active', true)
                        ->with('site')
                        ->orderBy('site_id'),
                ])
                ->get()
                ->sortBy(static fn (CompanyUserAccess $access): string => Str::lower($access->user?->name ?? ''))
                ->values();

            return response()->json([
                'data' => $accesses
                    ->map(fn (CompanyUserAccess $access): array => $this->companyUserPayload($access))
                    ->values(),
            ]);
        });
    }

    public function storeCompanyUser(Request $request, Company $company): JsonResponse
    {
        $request->merge([
            'email' => is_string($request->input('email'))
                ? Str::lower(trim($request->input('email')))
                : $request->input('email'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:4096', 'confirmed'],
            'role_key' => ['required', Rule::in(array_keys(self::CAR_RENTAL_ROLE_PROFILES))],
            'site_scope' => ['required', Rule::in(['all', 'selected'])],
            'site_ids' => ['nullable', 'array', 'max:100'],
            'site_ids.*' => ['uuid', 'distinct'],
        ], $this->companyUserValidationMessages());

        $passwordValidation = Validator::make(
            $request->only(['password', 'password_confirmation']),
            ['password' => PasswordPolicy::rules()],
        );

        if ($passwordValidation->fails()) {
            throw ValidationException::withMessages([
                'password' => 'Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un symbole.',
            ]);
        }

        if ($data['site_scope'] === 'selected' && empty($data['site_ids'])) {
            throw ValidationException::withMessages([
                'site_ids' => 'Sélectionnez au moins une adresse pour un accès limité.',
            ]);
        }

        if (User::query()->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse courriel est déjà associée à un compte. Créez un nouvel utilisateur avec une autre adresse.',
            ]);
        }

        $profile = self::CAR_RENTAL_ROLE_PROFILES[$data['role_key']];
        $owner = $this->owner($request);

        $access = DB::transaction(function () use ($company, $data, $profile, $owner): CompanyUserAccess {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'system_role' => 'user',
                'two_factor_email_enabled' => true,
            ]);

            return $this->companyContext->within($company->id, function () use ($company, $data, $profile, $owner, $user): CompanyUserAccess {
                $siteIds = collect($data['site_ids'] ?? [])
                    ->filter()
                    ->unique()
                    ->values();

                if ($data['site_scope'] === 'selected') {
                    $activeSiteCount = Site::query()
                        ->where('company_id', $company->id)
                        ->where('is_active', true)
                        ->whereIn('id', $siteIds)
                        ->count();

                    if ($activeSiteCount !== $siteIds->count()) {
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
                    'permissions' => $profile['permissions'],
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
                    eventType: 'configuration.company_user_created',
                    companyId: $company->id,
                    actorId: $owner->id,
                    actorType: 'USER',
                    subjectType: User::class,
                    subjectId: $user->id,
                    metadata: [
                        'role_key' => $access->role_key,
                        'site_scope' => $access->site_scope,
                        'site_count' => $siteIds->count(),
                    ],
                );

                return $access->load([
                    'user',
                    'siteGrants' => static fn ($siteGrants) => $siteGrants
                        ->where('is_active', true)
                        ->with('site')
                        ->orderBy('site_id'),
                ]);
            });
        });

        $notificationSent = $this->sendAccountCreatedNotification($access, $company, $owner);

        return response()->json([
            'data' => $this->companyUserPayload($access),
            'notification' => [
                'sent' => $notificationSent,
            ],
        ], 201);
    }

    /**
     * Met à jour l'accès de l'utilisateur dans la société courante. Les
     * données personnelles globales ne sont modifiables ici que si le compte
     * n'est pas actif dans une autre société ; cela évite une modification
     * indirecte d'un autre périmètre.
     */
    public function updateCompanyUser(Request $request, Company $company, string $companyUserAccess): JsonResponse
    {
        $request->merge([
            'email' => is_string($request->input('email'))
                ? Str::lower(trim($request->input('email')))
                : $request->input('email'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'role_key' => ['required', Rule::in(array_keys(self::CAR_RENTAL_ROLE_PROFILES))],
            'site_scope' => ['required', Rule::in(['all', 'selected'])],
            'site_ids' => ['nullable', 'array', 'max:100'],
            'site_ids.*' => ['uuid', 'distinct'],
        ], $this->companyUserValidationMessages());

        if ($data['site_scope'] === 'selected' && empty($data['site_ids'])) {
            throw ValidationException::withMessages([
                'site_ids' => 'Sélectionnez au moins une adresse pour un accès limité.',
            ]);
        }

        $owner = $this->owner($request);

        return $this->companyContext->within($company->id, function () use ($company, $companyUserAccess, $data, $owner): JsonResponse {
            $access = $this->companyUserAccessFor($company, $companyUserAccess);
            $targetUser = $access->user;

            abort_unless($targetUser instanceof User, 404, 'Utilisateur introuvable.');

            if ($access->role_key === 'owner') {
                throw ValidationException::withMessages([
                    'user' => 'Le compte propriétaire ne peut pas être modifié depuis la gestion des utilisateurs de cette société.',
                ]);
            }

            $profile = self::CAR_RENTAL_ROLE_PROFILES[$data['role_key']];
            $name = trim($data['name']);
            $personalDataChanged = $targetUser->name !== $name || $targetUser->email !== $data['email'];

            if ($personalDataChanged && $this->userHasOtherActiveCompanyAccess($targetUser, $company)) {
                throw ValidationException::withMessages([
                    'email' => 'Les données personnelles ne peuvent pas être modifiées ici car ce compte est également actif dans une autre société.',
                ]);
            }

            if ($targetUser->email !== $data['email'] && User::query()
                ->where('email', $data['email'])
                ->where('id', '!=', $targetUser->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Cette adresse courriel est déjà associée à un autre compte.',
                ]);
            }

            $siteIds = collect($data['site_ids'] ?? [])
                ->filter()
                ->unique()
                ->values();
            $this->assertSelectedSitesBelongToCompany($company, $data['site_scope'], $siteIds->all());

            $targetUser->forceFill([
                'name' => $name,
                'email' => $data['email'],
            ])->save();

            $access->forceFill([
                'role_key' => $data['role_key'],
                'site_scope' => $data['site_scope'],
                'permissions' => $profile['permissions'],
            ])->save();

            $this->syncCompanyUserSiteAccess($company, $access, $data['site_scope'], $siteIds->all());

            $access = $this->companyUserAccessFor($company, $access->id);
            $this->audit->record(
                eventType: 'configuration.company_user_updated',
                companyId: $company->id,
                actorId: $owner->id,
                actorType: 'USER',
                subjectType: User::class,
                subjectId: $targetUser->id,
                metadata: [
                    'role_key' => $access->role_key,
                    'site_scope' => $access->site_scope,
                    'site_count' => $access->siteGrants->where('is_active', true)->count(),
                    'personal_data_changed' => $personalDataChanged,
                ],
            );

            return response()->json([
                'data' => $this->companyUserPayload($access),
            ]);
        });
    }

    /**
     * Désactive ou réactive l'accès pour cette société uniquement. Aucun
     * compte ni événement d'audit n'est supprimé.
     */
    /**
     * Accorde ou retire le droit de saisir le taux HTG/USD du groupe. Ce droit
     * est porté par la personne, quelle que soit sa société.
     */
    public function updateExchangeRateAccess(Request $request, Company $company, string $companyUserAccess): JsonResponse
    {
        $data = $request->validate([
            'allowed' => ['required', 'boolean'],
        ]);
        $owner = $this->owner($request);

        return $this->companyContext->within($company->id, function () use ($company, $companyUserAccess, $data, $owner): JsonResponse {
            $access = $this->companyUserAccessFor($company, $companyUserAccess);
            $user = $access->user;

            if (! $user instanceof User || $user->system_role === 'owner') {
                throw ValidationException::withMessages([
                    'user' => 'Le propriétaire saisit toujours le taux : ce droit ne se modifie pas pour lui.',
                ]);
            }

            if ((bool) $user->can_manage_exchange_rates !== (bool) $data['allowed']) {
                $user->forceFill(['can_manage_exchange_rates' => (bool) $data['allowed']])->save();
                $this->audit->record(
                    eventType: $data['allowed'] ? 'configuration.exchange_rate_access_granted' : 'configuration.exchange_rate_access_revoked',
                    companyId: $company->id,
                    actorId: $owner->id,
                    actorType: 'USER',
                    subjectType: User::class,
                    subjectId: $user->id,
                    metadata: [],
                );
            }

            return response()->json([
                'data' => $this->companyUserPayload($this->companyUserAccessFor($company, $access->id)),
            ]);
        });
    }

    public function updateCompanyUserStatus(Request $request, Company $company, string $companyUserAccess): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $owner = $this->owner($request);

        return $this->companyContext->within($company->id, function () use ($company, $companyUserAccess, $data, $owner): JsonResponse {
            $access = $this->companyUserAccessFor($company, $companyUserAccess);

            if ($access->role_key === 'owner') {
                throw ValidationException::withMessages([
                    'user' => 'Le compte propriétaire ne peut pas être désactivé depuis cette page.',
                ]);
            }

            $changed = $access->is_active !== (bool) $data['is_active'];
            $access->forceFill(['is_active' => (bool) $data['is_active']])->save();
            $access = $this->companyUserAccessFor($company, $access->id);

            if ($changed) {
                $this->audit->record(
                    eventType: $access->is_active
                        ? 'configuration.company_user_reactivated'
                        : 'configuration.company_user_deactivated',
                    companyId: $company->id,
                    actorId: $owner->id,
                    actorType: 'USER',
                    subjectType: User::class,
                    subjectId: $access->user_id,
                    metadata: [
                        'role_key' => $access->role_key,
                        'site_scope' => $access->site_scope,
                    ],
                );
            }

            return response()->json([
                'data' => $this->companyUserPayload($access),
            ]);
        });
    }

    /**
     * Le propriétaire peut définir un nouveau mot de passe uniquement pour un
     * compte rattaché à cette seule société. Le propriétaire ne voit jamais
     * l'ancien mot de passe et les sessions existantes sont révoquées.
     */
    public function resetCompanyUserPassword(Request $request, Company $company, string $companyUserAccess): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:4096', 'confirmed'],
        ], $this->companyUserValidationMessages());
        $passwordValidation = Validator::make(
            $request->only(['password', 'password_confirmation']),
            ['password' => PasswordPolicy::rules()],
        );

        if ($passwordValidation->fails()) {
            throw ValidationException::withMessages([
                'password' => 'Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule, un chiffre et un symbole.',
            ]);
        }

        $owner = $this->owner($request);

        return $this->companyContext->within($company->id, function () use ($company, $companyUserAccess, $data, $owner): JsonResponse {
            $access = $this->companyUserAccessFor($company, $companyUserAccess);
            $targetUser = $access->user;

            abort_unless($targetUser instanceof User, 404, 'Utilisateur introuvable.');

            if ($access->role_key === 'owner') {
                throw ValidationException::withMessages([
                    'user' => 'Utilisez la réinitialisation personnelle du compte propriétaire.',
                ]);
            }

            if ($this->userHasOtherActiveCompanyAccess($targetUser, $company)) {
                throw ValidationException::withMessages([
                    'password' => 'La réinitialisation par une société n’est pas disponible pour un compte actif dans une autre société. L’utilisateur doit utiliser la réinitialisation personnelle.',
                ]);
            }

            $targetUser->forceFill([
                'password' => Hash::make($data['password']),
                'two_factor_email_enabled' => true,
                'two_factor_email_verified_at' => null,
            ])->save();
            ApiAccessToken::revokeAllFor($targetUser);

            $this->audit->record(
                eventType: 'configuration.company_user_password_reset',
                companyId: $company->id,
                actorId: $owner->id,
                actorType: 'USER',
                subjectType: User::class,
                subjectId: $targetUser->id,
                metadata: [
                    'role_key' => $access->role_key,
                ],
            );

            return response()->json([
                'message' => 'Mot de passe réinitialisé. Les sessions existantes ont été fermées. Un code par courriel sera demandé à la prochaine connexion.',
            ]);
        });
    }

    /**
     * Supprime le compte et son accès de manière définitive, sans effacer les
     * opérations ni le journal d'audit. Cette action est réservée à un compte
     * qui n'appartient à aucune autre société.
     */
    public function destroyCompanyUser(Request $request, Company $company, string $companyUserAccess): JsonResponse
    {
        $request->merge([
            'confirmation_email' => is_string($request->input('confirmation_email'))
                ? Str::lower(trim($request->input('confirmation_email')))
                : $request->input('confirmation_email'),
        ]);
        $data = $request->validate([
            'confirmation_email' => ['required', 'email:rfc', 'max:254'],
        ]);
        $owner = $this->owner($request);

        return $this->companyContext->within($company->id, function () use ($company, $companyUserAccess, $data, $owner): JsonResponse {
            $access = $this->companyUserAccessFor($company, $companyUserAccess);
            $targetUser = $access->user;

            abort_unless($targetUser instanceof User, 404, 'Utilisateur introuvable.');

            if ($access->role_key === 'owner' || $targetUser->system_role === 'owner') {
                throw ValidationException::withMessages([
                    'user' => 'Le compte propriétaire ne peut pas être supprimé depuis cette page.',
                ]);
            }

            if ($this->userHasAnyOtherCompanyAccess($targetUser, $company)) {
                throw ValidationException::withMessages([
                    'user' => 'La suppression définitive n’est pas disponible pour un compte rattaché à une autre société.',
                ]);
            }

            if (! hash_equals($targetUser->email, $data['confirmation_email'])) {
                throw ValidationException::withMessages([
                    'confirmation_email' => 'Saisissez exactement le courriel de l’utilisateur à supprimer.',
                ]);
            }

            $this->audit->record(
                eventType: 'configuration.company_user_deleted',
                companyId: $company->id,
                actorId: $owner->id,
                actorType: 'USER',
                subjectType: User::class,
                subjectId: $targetUser->id,
                metadata: [
                    'role_key' => $access->role_key,
                    'site_scope' => $access->site_scope,
                    'deletion' => 'permanent',
                ],
            );

            $targetUser->delete();

            return response()->json([], 204);
        });
    }

    private function companyUserAccessFor(Company $company, string $companyUserAccess): CompanyUserAccess
    {
        $access = CompanyUserAccess::query()
            ->where('company_id', $company->id)
            ->whereKey($companyUserAccess)
            ->with([
                'user',
                'siteGrants' => static fn ($siteGrants) => $siteGrants
                    ->where('is_active', true)
                    ->with('site')
                    ->orderBy('site_id'),
            ])
            ->first();

        abort_if($access === null, 404, 'Utilisateur introuvable pour cette société.');

        return $access;
    }

    private function userHasOtherActiveCompanyAccess(User $user, Company|string $company): bool
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        return CompanyUserAccess::query()
            ->where('user_id', $user->id)
            ->where('company_id', '!=', $companyId)
            ->where('is_active', true)
            ->exists();
    }

    private function userHasAnyOtherCompanyAccess(User $user, Company|string $company): bool
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        return CompanyUserAccess::query()
            ->where('user_id', $user->id)
            ->where('company_id', '!=', $companyId)
            ->exists();
    }

    private function sendAccountCreatedNotification(
        CompanyUserAccess $access,
        Company $company,
        User $owner,
    ): bool {
        $recipient = $access->user;

        if (! $recipient instanceof User) {
            return false;
        }

        try {
            Mail::to($recipient->email, $recipient->name)->send(new AccountCreatedMail(
                $recipient->name,
                $company->display_name,
                $this->companyUserRoleLabel($access->role_key),
            ));
        } catch (Throwable) {
            $this->audit->record(
                eventType: 'configuration.company_user_creation_notification_failed',
                companyId: $company->id,
                actorId: $owner->id,
                actorType: 'USER',
                subjectType: User::class,
                subjectId: $recipient->id,
                metadata: ['role_key' => $access->role_key],
            );

            return false;
        }

        $this->audit->record(
            eventType: 'configuration.company_user_creation_notification_sent',
            companyId: $company->id,
            actorId: $owner->id,
            actorType: 'USER',
            subjectType: User::class,
            subjectId: $recipient->id,
            metadata: ['role_key' => $access->role_key],
        );

        return true;
    }

    private function companyUserRoleLabel(string $roleKey): string
    {
        return match ($roleKey) {
            'car_rental_administrator' => 'Administrateur Car Rental',
            'car_rental_agent' => 'Agent de location',
            'car_rental_fleet' => 'Gestionnaire de flotte',
            default => 'Utilisateur Clientèle Group ERP',
        };
    }

    /** @param array<int, string> $siteIds */
    private function assertSelectedSitesBelongToCompany(Company $company, string $siteScope, array $siteIds): void
    {
        if ($siteScope !== 'selected') {
            return;
        }

        $activeSiteCount = Site::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereIn('id', $siteIds)
            ->count();

        if ($activeSiteCount !== count($siteIds)) {
            throw ValidationException::withMessages([
                'site_ids' => 'Sélectionnez uniquement des adresses actives de cette société.',
            ]);
        }
    }

    /** @param array<int, string> $siteIds */
    private function syncCompanyUserSiteAccess(
        Company $company,
        CompanyUserAccess $access,
        string $siteScope,
        array $siteIds,
    ): void {
        $query = CompanyUserSiteAccess::query()
            ->where('company_user_access_id', $access->id)
            ->where('company_id', $company->id);

        if ($siteScope !== 'selected') {
            $query->update(['is_active' => false]);

            return;
        }

        $query->whereNotIn('site_id', $siteIds)->update(['is_active' => false]);

        foreach ($siteIds as $siteId) {
            CompanyUserSiteAccess::query()->updateOrCreate(
                [
                    'company_user_access_id' => $access->id,
                    'site_id' => $siteId,
                ],
                [
                    'company_id' => $company->id,
                    'is_active' => true,
                ],
            );
        }
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->system_role === 'owner', 500, 'Contexte propriétaire manquant.');

        return $user;
    }

    private function canonicalCode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $code = Str::upper(Str::slug(trim($value), '-'));

        return $code === '' ? null : $code;
    }

    /** @return array<string, string> */
    private function companyValidationMessages(): array
    {
        return [
            'code.required' => 'Saisissez un code de société.',
            'code.max' => 'Le code de société ne peut pas dépasser 32 caractères.',
            'code.regex' => 'Utilisez au moins 2 caractères : lettres, chiffres, tirets ou traits de soulignement.',
            'code.unique' => 'Ce code de société est déjà utilisé. Choisissez un autre code.',
            'legal_name.required' => 'Saisissez la dénomination légale.',
            'legal_name.max' => 'La dénomination légale ne peut pas dépasser 255 caractères.',
            'rental_contract_terms.max' => 'Les conditions du contrat ne peuvent pas dépasser 40 000 caractères.',
            'display_name.required' => 'Saisissez le nom affiché.',
            'display_name.max' => 'Le nom affiché ne peut pas dépasser 255 caractères.',
            'base_currency.required' => 'Sélectionnez la devise de base.',
            'base_currency.in' => 'Sélectionnez HTG ou USD comme devise de base.',
        ];
    }

    /** @return array<string, string> */
    private function siteValidationMessages(): array
    {
        return [
            'code.required' => 'Saisissez un code d’adresse.',
            'code.max' => 'Le code d’adresse ne peut pas dépasser 32 caractères.',
            'code.regex' => 'Utilisez au moins 2 caractères : lettres, chiffres, tirets ou traits de soulignement.',
            'code.unique' => 'Ce code d’adresse est déjà utilisé pour cette société.',
            'name.required' => 'Saisissez le nom de l’adresse.',
            'name.max' => 'Le nom de l’adresse ne peut pas dépasser 255 caractères.',
            'address.required' => 'Saisissez l’adresse complète.',
            'address.max' => 'L’adresse complète ne peut pas dépasser 1 000 caractères.',
        ];
    }

    /** @return array<string, string> */
    private function cashRegisterValidationMessages(): array
    {
        return [
            'site_id.required' => 'Sélectionnez une adresse.',
            'site_id.uuid' => 'Sélectionnez une adresse valide.',
            'code.required' => 'Saisissez un code de caisse.',
            'code.max' => 'Le code de caisse ne peut pas dépasser 32 caractères.',
            'code.regex' => 'Utilisez au moins 2 caractères : lettres, chiffres, tirets ou traits de soulignement.',
            'code.unique' => 'Ce code de caisse est déjà utilisé pour cette société.',
            'name.required' => 'Saisissez le nom de la caisse.',
            'name.max' => 'Le nom de la caisse ne peut pas dépasser 255 caractères.',
        ];
    }

    /** @return array<string, string> */
    private function companyUserValidationMessages(): array
    {
        return [
            'name.required' => 'Saisissez le nom complet de l’utilisateur.',
            'name.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'email.required' => 'Saisissez le courriel personnel de l’utilisateur.',
            'email.email' => 'Saisissez un courriel valide.',
            'email.max' => 'Le courriel ne peut pas dépasser 254 caractères.',
            'password.required' => 'Saisissez un mot de passe initial.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.max' => 'Le mot de passe est trop long.',
            'role_key.required' => 'Sélectionnez un profil Car Rental.',
            'role_key.in' => 'Sélectionnez un profil Car Rental valide.',
            'site_scope.required' => 'Sélectionnez la portée des adresses.',
            'site_scope.in' => 'Sélectionnez une portée d’adresses valide.',
            'site_ids.array' => 'Les adresses sélectionnées ne sont pas valides.',
            'site_ids.max' => 'Trop d’adresses sont sélectionnées.',
            'site_ids.*.uuid' => 'Une adresse sélectionnée n’est pas valide.',
            'site_ids.*.distinct' => 'Une adresse ne peut être sélectionnée qu’une fois.',
        ];
    }

    /** @return array<string, mixed> */
    private function companyPayload(Company $company): array
    {
        return [
            'id' => $company->id,
            'code' => $company->code,
            'legal_name' => $company->legal_name,
            'display_name' => $company->display_name,
            'base_currency' => $company->base_currency,
            'timezone' => $company->timezone,
            'timezone_label' => $company->timezone_display_name,
            'is_active' => $company->is_active,
            'legal_representative' => $company->legal_representative,
            'tax_identification_number' => $company->tax_identification_number,
            'legal_address' => $company->legal_address,
            'phone_numbers' => $company->phone_numbers,
            'rental_contract_terms' => $company->rental_contract_terms,
            'roadside_assistance_phone' => $company->roadside_assistance_phone,
            'rental_airport_fee_usd' => (string) $company->rental_airport_fee_usd,
            'rental_cleaning_fee_usd' => (string) $company->rental_cleaning_fee_usd,
            'sites' => $company->relationLoaded('sites')
                ? $company->sites->map(fn (Site $site): array => $this->sitePayload($site))->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function sitePayload(Site $site): array
    {
        return [
            'id' => $site->id,
            'code' => $site->code,
            'name' => $site->name,
            'address' => $site->address,
            'is_active' => $site->is_active,
            'cash_registers' => $site->relationLoaded('cashRegisters')
                ? $site->cashRegisters
                    ->map(fn (CashRegister $register): array => $this->cashRegisterPayload($register))
                    ->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function cashRegisterPayload(CashRegister $register): array
    {
        return [
            'id' => $register->id,
            'site_id' => $register->site_id,
            'code' => $register->code,
            'name' => $register->name,
            'automatic_print_enabled' => $register->automatic_print_enabled,
            'customer_display_enabled' => $register->customer_display_enabled,
            'is_active' => $register->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function companyUserPayload(CompanyUserAccess $access): array
    {
        $user = $access->user;

        return [
            'id' => $access->id,
            'user_id' => $access->user_id,
            'name' => $user?->name,
            'email' => $user?->email,
            'role_key' => $access->role_key,
            'site_scope' => $access->site_scope,
            'is_active' => $access->is_active,
            'is_system_owner' => $access->role_key === 'owner',
            'can_manage_exchange_rates' => $user instanceof User && $user->canManageExchangeRates(),
            'can_edit_personal_profile' => $user instanceof User
                && $access->role_key !== 'owner'
                && ! $this->userHasOtherActiveCompanyAccess($user, $access->company_id),
            'can_delete_permanently' => $user instanceof User
                && $access->role_key !== 'owner'
                && $user->system_role !== 'owner'
                && ! $this->userHasAnyOtherCompanyAccess($user, $access->company_id),
            'sites' => $access->relationLoaded('siteGrants')
                ? $access->siteGrants
                    ->filter(fn (CompanyUserSiteAccess $grant): bool => $grant->is_active && $grant->site !== null)
                    ->map(fn (CompanyUserSiteAccess $grant): array => [
                        'id' => $grant->site->id,
                        'code' => $grant->site->code,
                        'name' => $grant->site->name,
                    ])
                    ->values()
                : [],
        ];
    }
}
