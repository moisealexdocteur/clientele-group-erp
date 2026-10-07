<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

final class PasswordPolicy
{
    /** @var array<int, string> */
    private const COMMON_PASSWORDS = [
        '123456789',
        '1234567890',
        'azerty123',
        'clientele123',
        'motdepasse123',
        'password123',
        'qwerty123',
        'welcome123',
        'administrator',
        'administrateur',
        'changeme123',
        'letmein123',
    ];

    /**
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'max:4096',
            'confirmed',
            Password::min(12)
                ->mixedCase()
                ->numbers()
                ->symbols(),
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (in_array(Str::lower(trim((string) $value)), self::COMMON_PASSWORDS, true)) {
                    $fail('Ce mot de passe est trop courant.');
                }
            },
        ];
    }
}
