<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\CashSessionService;
use App\Support\Cash\CashPresenter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseCashSession extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CashPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly CashSessionService $cash,
    ) {
    }

    public function __invoke(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'declared_usd' => ['required', 'numeric', 'min:0', 'max:9999999', new DecimalAmount()],
            'declared_htg' => ['required', 'numeric', 'min:0', 'max:999999999', new DecimalAmount()],
            'variance_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'declared_usd.required' => 'Indiquez les espèces USD comptées (0 si aucune).',
            'declared_htg.required' => 'Indiquez les espèces HTG comptées (0 si aucune).',
        ]);

        $model = DB::transaction(function () use ($company, $access, $session, $data, $actor): CashSession {
            $model = $this->presenter->sessionFor($company, $access, $session, true);

            if ($model->status !== 'open') {
                throw ValidationException::withMessages(['session' => 'Cette session de caisse est déjà clôturée.']);
            }

            if (! $this->presenter->canClose($model, $access, $actor)) {
                abort(403, 'Seule la personne qui a ouvert la caisse ou un superviseur peut la clôturer.');
            }

            $pending = $this->presenter->pendingCashPayments($model);

            if ($pending > 0) {
                throw ValidationException::withMessages([
                    'session' => sprintf(
                        '%d paiement%s en espèces attend%s une approbation. Faites-le approuver ou rejeter avant la clôture.',
                        $pending,
                        $pending > 1 ? 's' : '',
                        $pending > 1 ? 'ent' : '',
                    ),
                ]);
            }

            $totals = $this->cash->totals($model);
            $declaredUsd = Money::toCents((string) $data['declared_usd']);
            $declaredHtg = Money::toCents((string) $data['declared_htg']);
            $varianceUsd = $declaredUsd - Money::toCents($totals['USD']['expected']);
            $varianceHtg = $declaredHtg - Money::toCents($totals['HTG']['expected']);
            $hasVariance = $varianceUsd !== 0 || $varianceHtg !== 0;
            $note = trim((string) ($data['variance_note'] ?? ''));

            if ($hasVariance && $note === '') {
                throw ValidationException::withMessages([
                    'variance_note' => 'Le montant compté diffère du montant attendu. Expliquez l’écart.',
                ]);
            }

            $model->forceFill([
                'status' => 'closed',
                'closed_by' => $actor?->id,
                'closed_at' => now()->utc(),
                'expected_usd' => $totals['USD']['expected'],
                'expected_htg' => $totals['HTG']['expected'],
                'declared_usd' => Money::fromCents($declaredUsd),
                'declared_htg' => Money::fromCents($declaredHtg),
                'variance_usd' => Money::fromCents($varianceUsd),
                'variance_htg' => Money::fromCents($varianceHtg),
                'variance_note' => $hasVariance ? $note : null,
                'review_status' => $hasVariance ? 'pending' : 'none',
            ])->save();

            return $model;
        });

        $this->audit->record(
            eventType: $model->review_status === 'pending' ? 'cash.session_closed_with_variance' : 'cash.session_closed',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $model->id,
            metadata: [
                'cash_register' => $model->cashRegister?->code,
                'expected_usd' => $model->expected_usd,
                'expected_htg' => $model->expected_htg,
                'declared_usd' => $model->declared_usd,
                'declared_htg' => $model->declared_htg,
                'variance_usd' => $model->variance_usd,
                'variance_htg' => $model->variance_htg,
            ],
        );

        return response()->json([
            'data' => $this->presenter->sessionPayload($model->fresh(['site', 'cashRegister', 'opener', 'closer', 'reviewer']), $access, $actor, true),
        ]);
    }
}
