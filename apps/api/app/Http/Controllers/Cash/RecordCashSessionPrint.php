<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Support\AuditLogger;
use App\Support\Cash\CashPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecordCashSessionPrint extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CashPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Journalise l'impression du rapport de fermeture ; la suivante est une réimpression. */
    public function __invoke(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $this->presenter->assertCanSeeCash($access);

        $model = $this->presenter->sessionFor($company, $access, $session);
        $reprint = $model->report_print_count > 0;
        $model->forceFill(['report_print_count' => $model->report_print_count + 1])->save();

        $this->audit->record(
            eventType: $reprint ? 'cash.session_report_reprinted' : 'cash.session_report_printed',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $model->id,
            metadata: ['print_count' => $model->report_print_count],
        );

        return response()->json([
            'data' => $this->presenter->sessionPayload($model, $access, $actor, true),
        ]);
    }
}
