<?php

namespace App\Http\Controllers;

use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\CompanySiteAuthorizer;
use App\Support\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Reçus de paiement : données d'impression 80 mm, journal des impressions
 * et vérification publique du QR, sans donnée client.
 */
final class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receipts,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
        private readonly CompanyContext $companyContext,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        [$record, $reservation] = $this->paymentFor($company, $access, $payment);

        return response()->json(['data' => $this->payload($company, $access, $record, $reservation)]);
    }

    /** Journalise chaque impression ; une impression après la première est une réimpression. */
    public function recordPrint(Request $request, string $payment): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $data = $request->validate([
            'copy' => ['required', 'in:client,administration'],
        ]);

        [$record, $reservation] = $this->paymentFor($company, $access, $payment);
        $reprint = $record->receipt_print_count > 0;
        $record->forceFill(['receipt_print_count' => $record->receipt_print_count + 1])->save();

        $this->audit->record(
            eventType: $reprint ? 'receipt.reprinted' : 'receipt.printed',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalPayment::class,
            subjectId: $record->id,
            metadata: [
                'receipt_number' => $this->receipts->display((string) $record->receipt_number),
                'copy' => $data['copy'],
                'print_count' => $record->receipt_print_count,
            ],
        );

        return response()->json(['data' => $this->payload($company, $access, $record, $reservation)]);
    }

    /**
     * Vérification publique du QR. Elle confirme seulement l'existence, le
     * montant et la date du reçu : aucun nom ni coordonnée de client.
     */
    public function verify(Request $request, string $companyCode, string $number): JsonResponse
    {
        $key = 'receipt-verify:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json(['message' => 'Trop de vérifications. Patientez une minute puis réessayez.'], 429);
        }

        RateLimiter::hit($key, 60);

        $invalid = response()->json([
            'valid' => false,
            'message' => 'Ce reçu ne peut pas être vérifié. Il est inconnu ou le code a été modifié.',
        ]);

        if (! preg_match('/^\d{8}$/', $number)) {
            return $invalid;
        }

        $company = Company::query()->where('code', $companyCode)->first();

        if (! $company instanceof Company) {
            return $invalid;
        }

        $signature = (string) $request->query('s', '');

        return $this->companyContext->within($company->id, function () use ($company, $number, $signature, $invalid): JsonResponse {
            $payment = CarRentalPayment::query()
                ->where('company_id', $company->id)
                ->where('receipt_number', $number)
                ->first();

            if (! $payment instanceof CarRentalPayment) {
                return $invalid;
            }

            $expected = $this->receipts->signature($company->id, $number, (string) $payment->amount, $payment->currency);

            if (! hash_equals($expected, $signature)) {
                return $invalid;
            }

            return response()->json([
                'valid' => true,
                'company' => $company->display_name,
                'number' => $this->receipts->display($number),
                'issued_at' => $payment->receipt_issued_at?->toIso8601String(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
            ]);
        });
    }

    /** @return array{0: CarRentalPayment, 1: CarRentalReservation} */
    private function paymentFor(Company $company, CompanyUserAccess $access, string $payment): array
    {
        $record = CarRentalPayment::query()
            ->with(['cashRegister', 'approver'])
            ->where('company_id', $company->id)
            ->whereKey($payment)
            ->first();

        abort_if($record === null || $record->receipt_number === null, 404, 'Reçu introuvable.');

        $reservation = CarRentalReservation::query()
            ->with(['site', 'vehicle', 'customerProfile'])
            ->where('company_id', $company->id)
            ->whereKey($record->reservation_id)
            ->firstOrFail();
        $this->siteAuthorizer->siteFor($company, $access, $reservation->site_id);

        return [$record, $reservation];
    }

    /** @return array<string, mixed> */
    private function payload(Company $company, CompanyUserAccess $access, CarRentalPayment $payment, CarRentalReservation $reservation): array
    {
        $vehicle = $reservation->vehicle;

        return [
            'payment_id' => $payment->id,
            'number' => $this->receipts->display((string) $payment->receipt_number),
            'issued_at' => $payment->receipt_issued_at?->toIso8601String(),
            'print_count' => $payment->receipt_print_count,
            'company' => [
                'name' => $company->legal_name,
                'display_name' => $company->display_name,
                'address' => $company->legal_address,
                'tax_identification_number' => $company->tax_identification_number,
                'phone_numbers' => $company->phone_numbers,
            ],
            'site' => $reservation->site?->name,
            'cash_register' => $payment->cashRegister?->name,
            'cashier' => $payment->approver?->name,
            'reservation_number' => $reservation->formattedNumber(),
            'customer' => $reservation->customerProfile?->display_name,
            // Ni plaque ni permis sur le reçu.
            'vehicle' => trim(implode(' ', array_filter([$vehicle?->make, $vehicle?->model]))),
            'kind' => $payment->payment_kind,
            'method' => $payment->method,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'reservation_currency' => $reservation->currency,
            'exchange_rate_htg_per_usd' => $payment->exchange_rate_htg_per_usd,
            'amount_in_reservation_currency' => $payment->amount_in_reservation_currency,
            // Référence bancaire réservée à la copie Administration des rôles autorisés.
            'bank_reference' => $access->allows('rental.payments.approve') ? $payment->bank_reference : null,
            'verification_url' => $this->receipts->verificationUrl($company, $payment),
        ];
    }

    private function company(Request $request): Company
    {
        $company = $request->attributes->get('clientele.company');
        abort_unless($company instanceof Company, 500, 'Contexte de société manquant.');

        return $company;
    }

    private function access(Request $request): CompanyUserAccess
    {
        $access = $request->attributes->get('clientele.company_access');
        abort_unless($access instanceof CompanyUserAccess, 500, 'Contexte d’accès manquant.');

        return $access;
    }
}
