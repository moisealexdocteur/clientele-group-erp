<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsSale;
use App\Support\AuditLogger;
use App\Support\AutoParts\SalePresenter;
use App\Support\CompanySiteAuthorizer;
use App\Support\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Journalise chaque impression du reçu ; une impression après la première est une réimpression. */
final class RecordSaleReceiptPrint extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly SalePresenter $presenter,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
        private readonly ReceiptService $receipts,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $sale): JsonResponse
    {
        $company = $this->company($request);
        $actor = $request->user();
        $data = $request->validate(['copy' => ['required', 'in:client,administration']]);

        $model = PartsSale::query()->where('company_id', $company->id)->whereKey($sale)->first();
        abort_if($model === null, 404, 'Vente introuvable.');
        $this->siteAuthorizer->siteFor($company, $this->access($request), $model->site_id);

        $reprint = $model->receipt_print_count > 0;
        $model->forceFill(['receipt_print_count' => $model->receipt_print_count + 1])->save();

        $this->audit->record(
            eventType: $reprint ? 'receipt.reprinted' : 'receipt.printed',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: PartsSale::class,
            subjectId: $model->id,
            metadata: [
                'receipt_number' => $this->receipts->display($model->number),
                'copy' => $data['copy'],
                'print_count' => $model->receipt_print_count,
            ],
        );

        return response()->json(['data' => $this->presenter->detail($model, $company)]);
    }
}
