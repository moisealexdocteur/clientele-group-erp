<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CarRentalSecurityDeposit;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CompanySiteAuthorizer;
use App\Support\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApprovePayment extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly ReceiptService $receipts,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request, string $reservation, string $payment): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $model = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation)
            ->first();

        abort_if($model === null, 404, 'Réservation introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        $record = DB::transaction(function () use ($company, $model, $payment, $actor): CarRentalPayment {
            $record = CarRentalPayment::query()
                ->where('company_id', $company->id)
                ->where('reservation_id', $model->id)
                ->whereKey($payment)
                ->lockForUpdate()
                ->first();

            abort_if($record === null, 404, 'Paiement introuvable.');

            if ($record->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'payment' => 'Seul un paiement soumis peut être approuvé.',
                ]);
            }

            $record->forceFill([
                'status' => 'approved',
                'approved_by' => $actor?->id,
                'approved_at' => now()->utc(),
            ])->save();
            $this->receipts->issue($record);

            if ($record->payment_kind === 'security_deposit') {
                CarRentalSecurityDeposit::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'payment_id' => $record->id,
                    ],
                    [
                        'reservation_id' => $model->id,
                        'method' => $record->method,
                        'status' => 'held',
                        'currency' => $record->currency,
                        'amount' => $record->amount,
                        'held_at' => $record->approved_at,
                        'released_at' => null,
                    ],
                );
            }

            return $record;
        });

        $this->audit->record(
            eventType: 'car_rental.payment_approved',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalPayment::class,
            subjectId: $record->id,
            metadata: [
                'reservation_id' => $model->id,
                'method' => $record->method,
                'kind' => $record->payment_kind,
                'currency' => $record->currency,
                'receipt_number' => $record->receipt_number === null ? null : $this->receipts->display($record->receipt_number),
            ],
        );

        return response()->json([
            'data' => $this->presenter->paymentPayload($record),
        ]);
    }
}
