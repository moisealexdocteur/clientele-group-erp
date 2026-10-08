<?php

namespace App\Support;

use App\Models\CarRentalPayment;
use App\Models\Company;

/**
 * Reçus de caisse : numéro sur huit chiffres par société, attribué à
 * l'approbation d'un paiement encaissé, et QR de vérification signé.
 * Le QR ne contient aucune donnée client.
 */
final class ReceiptService
{
    public const SEQUENCE_SCOPE = 'receipt';

    public function __construct(
        private readonly DocumentNumberService $numbers,
    ) {
    }

    /** À appeler dans la transaction d'approbation. Un crédit ne reçoit pas de reçu. */
    public function issue(CarRentalPayment $payment): void
    {
        if ($payment->receipt_number !== null || $payment->method === 'credit' || $payment->status !== 'approved') {
            return;
        }

        $payment->forceFill([
            'receipt_number' => $this->numbers->next($payment->company_id, self::SEQUENCE_SCOPE),
            'receipt_issued_at' => now()->utc(),
        ])->save();
    }

    public function display(string $number): string
    {
        return $this->numbers->display($number);
    }

    public function signature(string $companyId, string $number, string $amount, string $currency): string
    {
        $secret = (string) config('security.receipts.qr_signing_secret');

        if ($secret === '') {
            $secret = (string) config('app.key');
        }

        return substr(hash_hmac('sha256', implode('|', [$companyId, $number, number_format((float) $amount, 2, '.', ''), $currency]), $secret), 0, 20);
    }

    public function verificationUrl(Company $company, CarRentalPayment $payment): ?string
    {
        if ($payment->receipt_number === null) {
            return null;
        }

        return sprintf(
            '%s/verification/recu/%s/%s?s=%s',
            rtrim((string) config('app.url'), '/'),
            rawurlencode($company->code),
            $payment->receipt_number,
            $this->signature($company->id, $payment->receipt_number, (string) $payment->amount, $payment->currency),
        );
    }
}
