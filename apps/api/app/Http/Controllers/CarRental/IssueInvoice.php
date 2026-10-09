<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalInvoice;
use App\Models\CarRentalReservation;
use App\Models\CarRentalSecurityDeposit;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalPricing;
use App\Support\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueInvoice extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalPricing $pricing,
        private readonly AuditLogger $audit,
        private readonly DocumentNumberService $documentNumbers,
    ) {
    }

    /**
     * Émet la facture de la location retournée : numéro sur huit chiffres,
     * lignes, paiements approuvés, dépôt retenu et solde, figés à l'émission.
     * La facture ne mentionne ni la plaque ni le permis.
     */
    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $model = DB::transaction(function () use ($company, $access, $reservation, $actor): CarRentalReservation {
            $model = $this->lookup->reservationFor($company, $access, $reservation, ['vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections'], true);

            if ($model->state !== 'completed') {
                throw ValidationException::withMessages([
                    'reservation' => 'La facture s’émet après l’enregistrement du retour.',
                ]);
            }

            if (CarRentalInvoice::query()->where('company_id', $company->id)->where('reservation_id', $model->id)->exists()) {
                throw ValidationException::withMessages([
                    'reservation' => 'La facture de cette location est déjà émise.',
                ]);
            }

            if ($model->securityDeposits->contains(static fn (CarRentalSecurityDeposit $deposit): bool => $deposit->status === 'held')) {
                throw ValidationException::withMessages([
                    'reservation' => 'Le dépôt de garantie doit être réglé par un administrateur avant la facture.',
                ]);
            }

            $snapshot = $this->pricing->invoiceSnapshot($company, $model);

            CarRentalInvoice::query()->create([
                'company_id' => $company->id,
                'reservation_id' => $model->id,
                'invoice_number' => $this->documentNumbers->next($company->id, 'car_rental_invoice'),
                'currency' => $model->currency,
                'snapshot' => $snapshot,
                'total' => $snapshot['totals']['total'],
                'balance_due' => $snapshot['totals']['balance_due'],
                'issued_by' => $actor?->id,
                'issued_at' => now()->utc(),
            ]);

            return $model->load(['site', 'invoice']);
        });

        $invoice = $model->invoice;

        $this->audit->record(
            eventType: 'car_rental.invoice_issued',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalInvoice::class,
            subjectId: $invoice?->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'invoice_number' => $invoice?->formattedNumber(),
                'currency' => $invoice?->currency,
                'total' => $invoice?->total,
                'balance_due' => $invoice?->balance_due,
            ],
        );

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
        ], 201);
    }
}
