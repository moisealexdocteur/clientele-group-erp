<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Montants monétaires en centimes entiers et taux en dix-millièmes entiers.
 * Les montants viennent de la base ou de la saisie sous forme de chaînes
 * décimales ; ils ne passent jamais par un flottant pour une somme, une
 * comparaison, une conversion ou une signature.
 */
final class Money
{
    public const RATE_SCALE = 4;

    /** « 12.345 » en centimes : 1235 (arrondi au plus proche, moitié vers le haut). */
    public static function toCents(string|int|null $value): int
    {
        return self::toScaled($value, 2);
    }

    public static function fromCents(int $cents): string
    {
        return self::fromScaled($cents, 2);
    }

    /** Forme canonique « 1234.50 ». */
    public static function normalize(string|int|null $value): string
    {
        return self::fromCents(self::toCents($value));
    }

    /** Taux HTG pour 1 USD en dix-millièmes : « 130.5 » donne 1305000. */
    public static function rateUnits(string|int|null $value): int
    {
        return self::toScaled($value, self::RATE_SCALE);
    }

    public static function normalizeRate(string|int|null $value): string
    {
        return self::fromScaled(self::rateUnits($value), self::RATE_SCALE);
    }

    /**
     * Conversion HTG ↔ USD au taux donné, arrondie au centime.
     * Montant et taux restent des chaînes décimales.
     */
    public static function convert(string $amount, string $from, string $to, string $rateHtgPerUsd): string
    {
        $cents = self::toCents($amount);

        if ($from === $to) {
            return self::fromCents($cents);
        }

        $rate = self::rateUnits($rateHtgPerUsd);

        if ($rate <= 0) {
            throw new InvalidArgumentException('Taux invalide.');
        }

        $scale = 10 ** self::RATE_SCALE;

        return self::fromCents($from === 'HTG'
            ? self::divideRounded($cents * $scale, $rate)
            : self::divideRounded($cents * $rate, $scale));
    }

    public static function toScaled(string|int|null $value, int $scale): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * (10 ** $scale);
        }

        $text = trim($value);

        if (! preg_match('/^([+-])?(\d+)(?:\.(\d*))?$/', $text, $parts)) {
            throw new InvalidArgumentException('Montant invalide.');
        }

        $digits = $parts[3] ?? '';
        $kept = str_pad(substr($digits, 0, $scale), $scale, '0');
        $next = strlen($digits) > $scale ? (int) $digits[$scale] : 0;
        $units = ((int) $parts[2]) * (10 ** $scale) + (int) $kept + ($next >= 5 ? 1 : 0);

        return ($parts[1] ?? '') === '-' ? -$units : $units;
    }

    public static function fromScaled(int $units, int $scale): string
    {
        $sign = $units < 0 ? '-' : '';
        $absolute = abs($units);
        $factor = 10 ** $scale;

        return sprintf('%s%d.%0' . $scale . 'd', $sign, intdiv($absolute, $factor), $absolute % $factor);
    }

    /** Division entière arrondie au plus proche, moitié en s'éloignant de zéro. */
    public static function divideRounded(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $numerator = abs($numerator);
        $denominator = abs($denominator);

        return $sign * intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /** « 1 250,00 » pour un texte en français, sans passer par un flottant. */
    public static function formatFr(string|int|null $value): string
    {
        $cents = self::toCents($value);
        $absolute = abs($cents);

        return sprintf(
            '%s%s,%02d',
            $cents < 0 ? '-' : '',
            number_format(intdiv($absolute, 100), 0, '', ' '),
            $absolute % 100,
        );
    }

    /** @param iterable<string|int|null> $values */
    public static function sumCents(iterable $values): int
    {
        $total = 0;

        foreach ($values as $value) {
            $total += self::toCents($value);
        }

        return $total;
    }
}
