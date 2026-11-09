<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use App\Support\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Journalise l'impression du reçu de vente ou du certificat du fournisseur ; la deuxième est une réimpression. */
final class RecordMotoDocumentPrint extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
        private readonly ReceiptService $receipts,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $order): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $data = $request->validate(['document' => ['required', 'in:receipt,certificate']]);
        $model = $this->lookup->motoOrderFor($company, $access, $order);

        if ($model->status !== 'delivered') {
            throw ValidationException::withMessages(['order' => 'Le reçu et le certificat s’impriment après la remise.']);
        }

        $column = $data['document'] === 'receipt' ? 'receipt_print_count' : 'certificate_print_count';
        $reprint = $model->{$column} > 0;
        $model->forceFill([$column => $model->{$column} + 1])->save();

        $this->audit->record(
            eventType: match (true) {
                $data['document'] === 'receipt' && $reprint => 'receipt.reprinted',
                $data['document'] === 'receipt' => 'receipt.printed',
                $reprint => 'parts.moto_certificate_reprinted',
                default => 'parts.moto_certificate_printed',
            },
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: MotoOrder::class,
            subjectId: $model->id,
            metadata: ['receipt_number' => $this->receipts->display((string) $model->receipt_number), 'print_count' => $model->{$column}],
        );

        return response()->json(['data' => $this->presenter->orderPayload($model, $company, $access, [], true)]);
    }
}
