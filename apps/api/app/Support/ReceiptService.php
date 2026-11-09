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

    /**
     * Signature HMAC-SHA256 complète du reçu (256 bits, 64 caractères
     * hexadécimaux), sans troncature ; la vérification publique est aussi
     * limitée à 30 essais par minute. Le montant est signé sous forme décimale
     * canonique, sans flottant. Sans clé QR dédiée, aucune signature n'est
     * produite : la clé de l'application n'est jamais réutilisée.
     */
    public function signature(string $companyId, string $number, string $amount, string $currency): ?string
    {
        $secret = (string) config('security.receipts.qr_signing_secret');

        if (strlen($secret) < 32) {
            return null;
        }

        return hash_hmac('sha256', implode('|', [$companyId, $number, Money::normalize($amount), $currency]), $secret);
    }

    /**
     * Code de vérification imprimé sur le reçu (8 caractères de la signature,
     * « 1A2B-3C4D ») : un reçu recopié ou inventé n'a pas le bon code.
     */
    public function securityCode(string $companyId, string $number, string $amount, string $currency): ?string
    {
        $signature = $this->signature($companyId, $number, $amount, $currency);

        if ($signature === null) {
            return null;
        }

        $code = strtoupper(substr($signature, 0, 8));

        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    public function verificationUrl(Company $company, CarRentalPayment $payment): ?string
    {
        if ($payment->receipt_number === null) {
            return null;
        }

        return $this->verificationUrlFor($company, $payment->receipt_number, (string) $payment->amount, $payment->currency);
    }

    /** Adresse du QR pour tout reçu de la société : paiement Car Rental ou vente Auto Parts. */
    public function verificationUrlFor(Company $company, string $number, string $amount, string $currency): ?string
    {
        $signature = $this->signature($company->id, $number, $amount, $currency);

        if ($signature === null) {
            return null;
        }

        return sprintf(
            '%s/verification/recu/%s/%s?s=%s',
            rtrim((string) config('app.url'), '/'),
            rawurlencode($company->code),
            $number,
            $signature,
        );
    }
}
