<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Montant saisi en décimal : chiffres, deux décimales au plus, sans exposant
 * ni séparateur de milliers. Il est ensuite traité en centimes entiers.
 */
final class DecimalAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Un nombre JSON arrive en entier ou en flottant : sa forme texte la plus
        // courte (« 130.5 ») est contrôlée, puis traitée en chaîne décimale.
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^\d{1,9}(\.\d{1,2})?$/', trim($value)) !== 1) {
            $fail('Saisissez un montant avec deux décimales au plus, par exemple 130.00.');
        }
    }
}
