<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalInvoice;
use App\Models\CarRentalReservation;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\FileVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class AttachInvoiceFile extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
        private readonly FileVault $files,
    ) {
    }

    /**
     * Rattache le PDF de la facture, construit à partir du contenu figé,
     * puis l'envoie au client. La facture ne contient ni plaque ni permis.
     */
    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'file_id' => ['required', 'uuid'],
        ]);

        $file = $this->files->find($company, $data['file_id'], StoredFile::PURPOSE_RENTAL_INVOICE, 'file_id');

        $model = DB::transaction(function () use ($company, $access, $reservation, $file): CarRentalReservation {
            $model = $this->lookup->reservationFor($company, $access, $reservation, [], true);
            $invoice = CarRentalInvoice::query()
                ->where('company_id', $company->id)
                ->where('reservation_id', $model->id)
                ->lockForUpdate()
                ->first();

            if (! $invoice instanceof CarRentalInvoice) {
                throw ValidationException::withMessages(['reservation' => 'Émettez d’abord la facture.']);
            }

            if ($invoice->file_id !== null) {
                throw ValidationException::withMessages(['reservation' => 'Le PDF de cette facture est déjà enregistré.']);
            }

            $invoice->forceFill(['file_id' => $file->id])->save();

            return $model->load(['site', 'vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections', 'invoice']);
        });

        $this->audit->record(
            eventType: 'car_rental.invoice_pdf_stored',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalInvoice::class,
            subjectId: $model->invoice?->id,
            metadata: ['invoice_sha256' => $file->sha256],
        );

        $content = Storage::disk($file->disk)->get($file->path);
        $sent = is_string($content) && $model->invoice !== null && $this->customerNotifications->notifyInvoice($company, $model, [[
            'name' => 'Facture-' . $model->invoice->invoice_number . '.pdf',
            'content' => $content,
            'mime' => 'application/pdf',
        ]]);

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
            'customer_notification_sent' => $sent,
        ]);
    }
}
