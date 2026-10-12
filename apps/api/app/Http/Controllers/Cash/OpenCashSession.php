<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\CashSessionService;
use App\Support\Cash\CashPresenter;
use App\Support\CompanySiteAuthorizer;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenCashSession extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CashPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly CashSessionService $cash,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request, string $register): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'opening_usd' => ['required', 'numeric', 'min:0', 'max:9999999', new DecimalAmount()],
            'opening_htg' => ['required', 'numeric', 'min:0', 'max:999999999', new DecimalAmount()],
        ], [
            'opening_usd.required' => 'Indiquez le fond de caisse en USD (0 si aucun).',
            'opening_htg.required' => 'Indiquez le fond de caisse en HTG (0 si aucun).',
        ]);

        $model = CashRegister::query()
            ->where('company_id', $company->id)
            ->whereKey($register)
            ->where('is_active', true)
            ->first();

        abort_if($model === null, 404, 'Caisse active introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        try {
            $session = DB::transaction(function () use ($company, $model, $data, $actor): CashSession {
                if ($this->cash->openSessionFor($company, $model->id, true) !== null) {
                    throw ValidationException::withMessages([
                        'register' => 'Cette caisse est déjà ouverte.',
                    ]);
                }

                return CashSession::query()->create([
                    'company_id' => $company->id,
                    'site_id' => $model->site_id,
                    'cash_register_id' => $model->id,
                    'status' => 'open',
                    'opened_by' => $actor?->id,
                    'opened_at' => now()->utc(),
                    'opening_usd' => Money::normalize((string) $data['opening_usd']),
                    'opening_htg' => Money::normalize((string) $data['opening_htg']),
                ]);
            });
        } catch (QueryException) {
            // Deux ouvertures simultanées : l'index unique garde une seule session.
            throw ValidationException::withMessages([
                'register' => 'Cette caisse est déjà ouverte.',
            ]);
        }

        $this->audit->record(
            eventType: 'cash.session_opened',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $session->id,
            metadata: [
                'cash_register' => $model->code,
                'opening_usd' => $session->opening_usd,
                'opening_htg' => $session->opening_htg,
            ],
        );

        return response()->json([
            'data' => $this->presenter->sessionPayload($session->load(['site', 'cashRegister', 'opener']), $access, $actor, true),
        ], 201);
    }
}
