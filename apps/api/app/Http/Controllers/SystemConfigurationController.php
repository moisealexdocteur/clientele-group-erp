<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\Site;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
}
