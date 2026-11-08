<?php

namespace App\Support\AutoParts;

use App\Models\Company;
use App\Rules\DecimalAmount;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Règles de saisie d'un article, communes à la création et à la modification. */
final class ProductRules
{
    /** Référence et code-barres en majuscules, sans espaces superflus. */
    public function canonicalize(Request $request): void
    {
        foreach (['sku', 'barcode'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $value = Str::upper(preg_replace('/\s+/', ' ', trim($value)) ?? '');
                $request->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(Company $company, ?string $ignoreId = null): array
    {
        $unique = static fn (string $column) => Rule::unique('parts_products', $column)
            ->where(fn ($query) => $query->where('company_id', $company->id))
            ->ignore($ignoreId);

        return [
            'sku' => ['required', 'string', 'max:64', 'regex:/\A[A-Z0-9][A-Z0-9._\/ -]{0,63}\z/', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Z0-9-]{4,64}\z/', $unique('barcode')],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'brand' => ['nullable', 'string', 'max:80'],
            'unit' => ['required', 'string', 'max:24'],
            'retail_price_htg' => ['required', new DecimalAmount()],
            'wholesale_price_htg' => ['nullable', new DecimalAmount()],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.required' => 'Saisissez la référence de l’article.',
            'sku.regex' => 'La référence commence par une lettre ou un chiffre et contient seulement lettres, chiffres, points, tirets, barres obliques et espaces.',
            'sku.unique' => 'Cette référence existe déjà dans le catalogue.',
            'sku.max' => 'La référence ne peut pas dépasser 64 caractères.',
            'barcode.regex' => 'Le code-barres contient de 4 à 64 lettres, chiffres ou tirets.',
            'barcode.unique' => 'Ce code-barres est déjà attribué à un autre article.',
            'name.required' => 'Saisissez la désignation de l’article.',
            'name.max' => 'La désignation ne peut pas dépasser 255 caractères.',
            'category.max' => 'La catégorie ne peut pas dépasser 80 caractères.',
            'brand.max' => 'La marque ne peut pas dépasser 80 caractères.',
            'unit.required' => 'Saisissez l’unité de vente, par exemple pièce, paire ou jeu.',
            'unit.max' => 'L’unité ne peut pas dépasser 24 caractères.',
            'retail_price_htg.required' => 'Saisissez le prix de détail en HTG.',
            'reorder_level.required' => 'Saisissez le seuil de réapprovisionnement, 0 si aucun.',
            'reorder_level.integer' => 'Le seuil est un nombre entier de pièces.',
            'reorder_level.min' => 'Le seuil ne peut pas être négatif.',
            'reorder_level.max' => 'Le seuil ne peut pas dépasser 100 000.',
            'notes.max' => 'La note ne peut pas dépasser 2 000 caractères.',
        ];
    }

    /** Prix de détail positif ; prix de gros positif et pas plus élevé que le détail. */
    public function assertPrices(string $retail, ?string $wholesale): void
    {
        if (Money::toCents($retail) <= 0) {
            throw ValidationException::withMessages(['retail_price_htg' => 'Le prix de détail doit être supérieur à 0 HTG.']);
        }

        if ($wholesale !== null && Money::toCents($wholesale) <= 0) {
            throw ValidationException::withMessages(['wholesale_price_htg' => 'Le prix de gros doit être supérieur à 0 HTG, ou laissé vide.']);
        }

        if ($wholesale !== null && Money::toCents($wholesale) > Money::toCents($retail)) {
            throw ValidationException::withMessages(['wholesale_price_htg' => 'Le prix de gros ne peut pas dépasser le prix de détail.']);
        }
    }
}
