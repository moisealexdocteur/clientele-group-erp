<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Support\AuditLogger;
use App\Support\Cash\CashPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewCashSession extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CashPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Approbation d'un écart de clôture par un superviseur. */
    public function __invoke(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => 'Indiquez la décision ou la mesure prise.',
        ]);

        $model = DB::transaction(function () use ($company, $access, $session, $data, $actor): CashSession {
            $model = $this->presenter->sessionFor($company, $access, $session, true);

            if ($model->review_status !== 'pending') {
                throw ValidationException::withMessages(['session' => 'Aucun écart à approuver pour cette session.']);
            }

            $model->forceFill([
                'review_status' => 'approved',
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now()->utc(),
                'review_note' => trim((string) $data['note']),
            ])->save();

            return $model;
        });

        $this->audit->record(
            eventType: 'cash.session_variance_approved',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $model->id,
            metadata: [
                'variance_usd' => $model->variance_usd,
                'variance_htg' => $model->variance_htg,
                'self_review' => $model->closed_by !== null && $model->closed_by === $actor?->id,
            ],
        );

        return response()->json([
            'data' => $this->presenter->sessionPayload($model->fresh(['site', 'cashRegister', 'opener', 'closer', 'reviewer']), $access, $actor, true),
        ]);
    }
}
