<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Models\CarRentalSecurityDeposit;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SettleDeposit extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Règle le dépôt de garantie après le retour : libération totale ou
     * retenue d'un montant justifié. Réservé à l'administration.
     */
    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'retained_amount_usd' => ['required', 'numeric', 'min:0', 'max:999999', new DecimalAmount()],
            'reason' => ['nullable', 'required_unless:retained_amount_usd,0', 'string', 'max:500'],
        ], [
            'reason.required_unless' => 'Indiquez le motif de la retenue.',
        ]);

        $retained = Money::toCents((string) $data['retained_amount_usd']);

        [$model, $held, $applied] = DB::transaction(function () use ($company, $access, $reservation, $data, $actor, $retained): array {
            $model = $this->lookup->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'completed') {
                throw ValidationException::withMessages([
                    'reservation' => 'Le dépôt se règle après l’enregistrement du retour.',
                ]);
            }

            $deposits = CarRentalSecurityDeposit::query()
                ->where('company_id', $company->id)
                ->where('reservation_id', $model->id)
                ->where('status', 'held')
                ->where('currency', 'USD')
                ->orderBy('held_at')
                ->lockForUpdate()
                ->get();
            $held = Money::sumCents($deposits->map(static fn (CarRentalSecurityDeposit $deposit): string => (string) $deposit->amount));

            if ($deposits->isEmpty()) {
                throw ValidationException::withMessages([
                    'reservation' => 'Aucun dépôt de garantie retenu à régler pour cette location.',
                ]);
            }

            if ($retained > $held) {
                throw ValidationException::withMessages([
                    'retained_amount_usd' => sprintf('La retenue ne peut pas dépasser le dépôt retenu (USD %s).', Money::fromCents($held)),
                ]);
            }

            $remaining = $retained;
            $now = now()->utc();

            foreach ($deposits as $deposit) {
                $amount = Money::toCents((string) $deposit->amount);
                $apply = min($remaining, $amount);
                $remaining -= $apply;

                $deposit->forceFill([
                    'status' => $apply <= 0 ? 'released' : ($apply >= $amount ? 'forfeited' : 'partially_applied'),
                    'applied_amount' => Money::fromCents($apply),
                    'released_at' => $now,
                    'settlement_note' => $retained > 0 ? trim((string) $data['reason']) : null,
                    'settled_by' => $actor?->id,
                ])->save();
            }

            return [$model->load(['site', 'vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections']), $held, $retained];
        });

        $this->audit->record(
            eventType: 'car_rental.security_deposit_settled',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'held_usd' => Money::fromCents($held),
                'retained_usd' => Money::fromCents($applied),
                'released_usd' => Money::fromCents($held - $applied),
            ],
        );

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
        ]);
    }
}
