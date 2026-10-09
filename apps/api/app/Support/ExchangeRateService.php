<?php

namespace App\Support;

use App\Models\ExchangeRate;
use Illuminate\Validation\ValidationException;

/**
 * Taux HTG/USD du groupe en vigueur et conversions. Les montants convertis sont
 * arrondis au centime ; le taux appliqué est toujours conservé avec la
 * transaction pour pouvoir la justifier.
 */
final class ExchangeRateService
{
    public function current(): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('effective_at', '<=', now()->utc())
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function requireCurrent(string $field): ExchangeRate
    {
        $rate = $this->current();

        if (! $rate instanceof ExchangeRate) {
            throw ValidationException::withMessages([
                $field => 'Aucun taux HTG/USD n’est défini pour le groupe. Il doit être saisi dans Configuration avant un paiement dans une autre devise.',
            ]);
        }

        return $rate;
    }

    public function convert(float $amount, string $from, string $to, float $rateHtgPerUsd): float
    {
        if ($from === $to) {
            return round($amount, 2);
        }

        return $from === 'HTG'
            ? round($amount / $rateHtgPerUsd, 2)
            : round($amount * $rateHtgPerUsd, 2);
    }

    /** @return array<string, mixed>|null */
    public function payload(?ExchangeRate $rate): ?array
    {
        if (! $rate instanceof ExchangeRate) {
            return null;
        }

        return [
            'id' => $rate->id,
            'rate_htg_per_usd' => $rate->rate_htg_per_usd,
            'brh_reference_rate' => $rate->brh_reference_rate,
            'brh_reference_date' => $rate->brh_reference_date?->format('Y-m-d'),
            'below_brh' => $rate->below_brh,
            'note' => $rate->note,
            'effective_at' => $rate->effective_at?->toIso8601String(),
            'set_by' => $rate->relationLoaded('author') ? $rate->author?->name : null,
        ];
    }
}
