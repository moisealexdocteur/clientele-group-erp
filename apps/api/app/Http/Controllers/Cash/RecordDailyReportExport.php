<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecordDailyReportExport extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    /** Journalise une impression ou un export du rapport journalier. */
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'site_id' => ['nullable', 'uuid'],
            'format' => ['required', 'in:pdf,xlsx,print_a4'],
        ]);

        if (filled($data['site_id'] ?? null)) {
            $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        }

        $this->audit->record(
            eventType: str_starts_with($data['format'], 'print') ? 'report.daily_cash_printed' : 'report.daily_cash_exported',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            metadata: [
                'date' => $data['date'],
                'site_id' => $data['site_id'] ?? null,
                'format' => $data['format'],
            ],
        );

        return response()->json(['recorded' => true]);
    }
}
