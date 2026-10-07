<?php

namespace App\Support;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Produit des identifiants séquentiels de huit chiffres, affichés en deux
 * groupes de quatre. L'appel doit se faire dans la transaction métier.
 */
final class DocumentNumberService
{
    public function next(string $companyId, string $scope): string
    {
        $now = now()->utc();

        DB::table('document_sequences')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'scope' => $scope,
            'last_value' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DocumentSequence::query()
            ->where('company_id', $companyId)
            ->where('scope', $scope)
            ->lockForUpdate()
            ->firstOrFail();

        $next = $sequence->last_value + 1;

        if ($next > 99_999_999) {
            throw ValidationException::withMessages([
                'reference' => 'La séquence de documents a atteint sa limite de huit chiffres.',
            ]);
        }

        $sequence->forceFill([
            'last_value' => $next,
        ])->save();

        return str_pad((string) $next, 8, '0', STR_PAD_LEFT);
    }

    public function display(string $number): string
    {
        return substr($number, 0, 4) . ' ' . substr($number, 4, 4);
    }
}
