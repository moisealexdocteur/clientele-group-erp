<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Montants monétaires en centimes entiers. Les montants viennent de la base
 * sous forme de chaînes décimales ; ils ne passent jamais par un flottant
 * pour une somme, une comparaison ou une signature.
 */
final class Money
{
    public static function toCents(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        // Un flottant (saisie validée en « numeric ») est d'abord ramené à deux décimales en texte.
        $text = is_float($value) ? sprintf('%.2f', $value) : trim($value);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{0,}))?$/', $text, $parts)) {
            throw new InvalidArgumentException('Montant invalide.');
        }

        $fraction = str_pad(substr($parts[3] ?? '', 0, 3), 3, '0');
        // Arrondi au centime le plus proche, à partir du troisième chiffre.
        $cents = ((int) $parts[2]) * 100 + intdiv((int) $fraction, 10) + (((int) $fraction) % 10 >= 5 ? 1 : 0);

        return ($parts[1] ?? '') === '-' ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    /** Forme canonique « 1234.50 ». */
    public static function normalize(string|int|float|null $value): string
    {
        return self::fromCents(self::toCents($value));
    }
}
