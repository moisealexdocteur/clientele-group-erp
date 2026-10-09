<?php

namespace App\Support;

use App\Models\CarRentalPayment;
use App\Models\CarRentalSecurityDeposit;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de caisse commun : une seule session ouverte par caisse, chaque
 * entrée ou sortie d'espèces est un mouvement de la session. Les montants
 * HTG et USD restent séparés : rien n'est converti à la clôture.
 */
final class CashSessionService
{
    public const CURRENCIES = ['USD', 'HTG'];

    public function openSessionFor(Company $company, string $registerId, bool $lock = false): ?CashSession
    {
        return CashSession::query()
            ->where('company_id', $company->id)
            ->where('cash_register_id', $registerId)
            ->where('status', 'open')
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** Session ouverte exigée pour encaisser ou rendre des espèces. */
    public function requireOpenSession(Company $company, string $registerId, string $field): CashSession
    {
        $session = $this->openSessionFor($company, $registerId, true);

        if ($session === null) {
            $name = CashRegister::query()->where('company_id', $company->id)->whereKey($registerId)->value('name');

            throw ValidationException::withMessages([
                $field => sprintf('La caisse « %s » n’est pas ouverte. Ouvrez-la dans Caisse avant de manipuler des espèces.', $name ?? 'sélectionnée'),
            ]);
        }

        return $session;
    }

    /** Entrée d'espèces à l'approbation d'un paiement en espèces. */
    public function recordPayment(CarRentalPayment $payment, ?User $actor): ?CashMovement
    {
        if ($payment->method !== 'cash' || $payment->cash_session_id === null) {
            return null;
        }

        $session = CashSession::query()
            ->where('company_id', $payment->company_id)
            ->whereKey($payment->cash_session_id)
            ->lockForUpdate()
            ->first();

        if ($session === null || $session->status !== 'open') {
            throw ValidationException::withMessages([
                'payment' => 'La session de caisse de ce paiement est clôturée. Le paiement ne peut plus être approuvé.',
            ]);
        }

        $kind = $payment->payment_kind === 'security_deposit' ? 'deposit_payment' : 'rental_payment';

        return CashMovement::query()->firstOrCreate(
            ['kind' => $kind, 'payment_id' => $payment->id],
            [
                'company_id' => $payment->company_id,
                'cash_session_id' => $session->id,
                'direction' => 'in',
                'currency' => $payment->currency,
                'amount' => $payment->amount,
                'reservation_id' => $payment->reservation_id,
                'recorded_by' => $actor?->id,
                'occurred_at' => $payment->approved_at ?? now()->utc(),
            ],
        );
    }

    /**
     * Sortie d'espèces : la part non retenue d'un dépôt versé en espèces est
     * rendue depuis la caisse qui l'a reçu, dans sa session ouverte.
     */
    public function recordDepositRefund(Company $company, CarRentalSecurityDeposit $deposit, string|float $refund, ?User $actor): ?CashMovement
    {
        $cents = Money::toCents($refund);

        if ($deposit->method !== 'cash' || $cents <= 0 || $deposit->payment_id === null) {
            return null;
        }

        $registerId = CarRentalPayment::query()
            ->where('company_id', $company->id)
            ->whereKey($deposit->payment_id)
            ->value('cash_register_id');

        if ($registerId === null) {
            return null;
        }

        $session = $this->requireOpenSession($company, $registerId, 'reservation');

        return CashMovement::query()->create([
            'company_id' => $company->id,
            'cash_session_id' => $session->id,
            'kind' => 'deposit_refund',
            'direction' => 'out',
            'currency' => $deposit->currency ?? 'USD',
            'amount' => Money::fromCents($cents),
            'deposit_id' => $deposit->id,
            'reservation_id' => $deposit->reservation_id,
            'recorded_by' => $actor?->id,
            'occurred_at' => now()->utc(),
        ]);
    }

    /**
     * Totaux de la session par devise : fond, entrées, sorties et espèces attendues.
     *
     * @return array<string, array{opening: string, in: string, out: string, expected: string}>
     */
    public function totals(CashSession $session): array
    {
        $movements = CashMovement::query()
            ->where('company_id', $session->company_id)
            ->where('cash_session_id', $session->id)
            ->get(['direction', 'currency', 'amount']);

        $totals = [];

        foreach (self::CURRENCIES as $currency) {
            $opening = Money::toCents((string) ($currency === 'USD' ? $session->opening_usd : $session->opening_htg));
            $in = 0;
            $out = 0;

            foreach ($movements->where('currency', $currency) as $movement) {
                if ($movement->direction === 'in') {
                    $in += Money::toCents((string) $movement->amount);
                } else {
                    $out += Money::toCents((string) $movement->amount);
                }
            }

            $totals[$currency] = [
                'opening' => Money::fromCents($opening),
                'in' => Money::fromCents($in),
                'out' => Money::fromCents($out),
                'expected' => Money::fromCents($opening + $in - $out),
            ];
        }

        return $totals;
    }

    public function money(string|int|float $value): string
    {
        return Money::normalize($value);
    }
}
