<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                'rental.vehicles.read',
                'rental.vehicles.manage',
                'rental.calendar.read',
                'rental.payments.submit',
                'rental.payments.approve',
            ],
        ],
        'car_rental_agent' => [
            'permissions' => [
                'rental.availability.read',
                'rental.reservations.create',
                'rental.reservations.read',
                'rental.vehicles.read',
                'rental.calendar.read',
                'rental.payments.submit',
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

        return response()->json([
            'data' => $this->companyUserPayload($access),
        ], 201);
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
