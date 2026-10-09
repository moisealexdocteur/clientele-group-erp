<?php

namespace App\Support;

/** Aides de saisie de texte. */
final class Text
{
    public static function nullableTrimmed(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
