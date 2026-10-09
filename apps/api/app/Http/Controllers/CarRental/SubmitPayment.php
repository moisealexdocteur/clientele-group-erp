<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CashRegister;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CompanySiteAuthorizer;
use App\Support\ExchangeRateService;
use App\Support\FileVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SubmitPayment extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly ExchangeRateService $exchangeRates,
        private readonly FileVault $files,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request, string $reservation): JsonResponse
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

        $data = $request->validate([
            'payment_kind' => ['required', Rule::in(CarRentalPayment::KINDS)],
            'method' => ['required', Rule::in(CarRentalPayment::METHODS)],
            'currency' => ['required', Rule::in(['HTG', 'USD'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_register_id' => ['nullable', 'uuid', 'required_if:method,cash'],
            'bank_name' => ['nullable', 'string', 'max:64'],
            'bank_reference' => ['nullable', 'string', 'max:128'],
            'proof_file_id' => ['nullable', 'uuid', 'required_if:method,bank_transfer'],
        ], [
            'cash_register_id.required_if' => 'Sélectionnez la caisse qui reçoit le paiement en espèces.',
            'proof_file_id.required_if' => 'Ajoutez la photo ou le fichier du reçu de virement Sogebank.',
        ]);

        if ($data['method'] === 'bank_transfer' && filled($data['bank_name'] ?? null) && strcasecmp((string) $data['bank_name'], 'Sogebank') !== 0) {
            throw ValidationException::withMessages([
                'bank_name' => 'Les virements Car Rental doivent être déposés à la Sogebank.',
            ]);
        }

        if ($data['method'] === 'credit') {
            if (! $access->allows('rental.payments.credit')) {
                return response()->json([
                    'message' => 'Seul un administrateur ou le propriétaire peut accorder un crédit.',
                ], 403);
            }

            if ($data['payment_kind'] !== 'rental') {
                throw ValidationException::withMessages([
                    'method' => 'Le dépôt de garantie ne peut pas être accordé à crédit.',
                ]);
            }
        }

        if ($data['payment_kind'] === 'security_deposit' && $data['currency'] !== 'USD') {
            throw ValidationException::withMessages([
                'currency' => 'Le dépôt minimum de cette version est contrôlé en USD. Enregistrez le dépôt de garantie en USD.',
            ]);
        }

        $proof = $data['method'] === 'bank_transfer'
            ? $this->files->find($company, $data['proof_file_id'] ?? null, StoredFile::PURPOSE_PAYMENT_PROOF, 'proof_file_id')
            : null;

        // Un paiement dans l'autre devise est converti au taux en vigueur, conservé avec le paiement.
        $rate = $data['currency'] !== $model->currency
            ? $this->exchangeRates->requireCurrent('currency')
            : null;
        $converted = $this->exchangeRates->convert(
            (float) $data['amount'],
            $data['currency'],
            $model->currency,
            $rate === null ? 1.0 : (float) $rate->rate_htg_per_usd,
        );

        if ($proof !== null && $proof->site_id !== $model->site_id) {
            throw ValidationException::withMessages([
                'proof_file_id' => 'Le reçu doit être ajouté depuis l’adresse de la réservation.',
            ]);
        }

        $payment = DB::transaction(function () use ($company, $model, $data, $proof, $actor, $rate, $converted): CarRentalPayment {
            $cashRegisterId = $data['method'] === 'cash' ? ($data['cash_register_id'] ?? null) : null;

            if ($cashRegisterId !== null) {
                $registerExists = CashRegister::query()
                    ->where('company_id', $company->id)
                    ->where('site_id', $model->site_id)
                    ->whereKey($cashRegisterId)
                    ->where('is_active', true)
                    ->exists();

                if (! $registerExists) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'Cette caisse n’est pas active pour l’adresse de la réservation.',
                    ]);
                }
            }

            // Un crédit n'encaisse rien : il est accordé et approuvé par la même personne autorisée.
            $isCredit = $data['method'] === 'credit';
            $payment = new CarRentalPayment([
                'company_id' => $company->id,
                'reservation_id' => $model->id,
                'cash_register_id' => $cashRegisterId,
                'payment_kind' => $data['payment_kind'],
                'method' => $data['method'],
                'status' => $isCredit ? 'approved' : 'submitted',
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'bank_name' => $data['method'] === 'bank_transfer' ? 'Sogebank' : null,
                'proof_file_id' => $proof?->id,
                'proof_storage_key' => $proof?->path,
                'proof_sha256' => $proof?->sha256,
                'approved_by' => $isCredit ? $actor?->id : null,
                'approved_at' => $isCredit ? now()->utc() : null,
                'exchange_rate_htg_per_usd' => $rate?->rate_htg_per_usd,
                'amount_in_reservation_currency' => number_format($converted, 2, '.', ''),
            ]);
            $payment->setBankReference($data['method'] === 'bank_transfer' ? ($data['bank_reference'] ?? null) : null);
            $payment->save();

            return $payment;
        });

        $this->audit->record(
            eventType: 'car_rental.payment_submitted',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalPayment::class,
            subjectId: $payment->id,
            metadata: [
                'reservation_id' => $model->id,
                'method' => $payment->method,
                'kind' => $payment->payment_kind,
                'status' => $payment->status,
                'currency' => $payment->currency,
            ],
        );

        return response()->json([
            'data' => $this->presenter->paymentPayload($payment),
        ], 201);
    }
}
