<?php

namespace App\Support\CarRental;

use App\Support\Text;
use Illuminate\Validation\Rule;

/** Croquis des dommages : règles de saisie et normalisation. */
final class CarRentalInspectionRules
{
    /** Types de dommages notés sur le croquis. */
    public const DAMAGE_KINDS = ['scratch', 'dent', 'chip', 'broken', 'other'];

    /** @return array<string, array<int, mixed>> */
    public function damageMarkRules(): array
    {
        return [
            'damage_marks' => ['nullable', 'array', 'max:30'],
            'damage_marks.*.x' => ['required', 'numeric', 'between:0,1'],
            'damage_marks.*.y' => ['required', 'numeric', 'between:0,1'],
            'damage_marks.*.kind' => ['required', Rule::in(self::DAMAGE_KINDS)],
            'damage_marks.*.note' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{x: float, y: float, kind: string, note: string|null}>
     */
    public function damageMarks(array $data): array
    {
        return array_values(array_map(fn (array $mark): array => [
            'x' => round((float) $mark['x'], 4),
            'y' => round((float) $mark['y'], 4),
            'kind' => (string) $mark['kind'],
            'note' => Text::nullableTrimmed($mark['note'] ?? null),
        ], $data['damage_marks'] ?? []));
    }
}
