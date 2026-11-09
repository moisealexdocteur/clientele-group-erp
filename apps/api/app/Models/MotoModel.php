<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Modèle de motocyclette vendu (Haojin), avec son prix en HTG ou en USD. */
final class MotoModel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'make',
        'model',
        'displacement_cc',
        'cylinders',
        'price',
        'price_currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'displacement_cc' => 'integer',
            'cylinders' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return trim($this->make . ' ' . $this->model);
    }
}
