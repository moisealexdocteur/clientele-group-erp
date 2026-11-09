<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Article du catalogue Auto Parts : pièce, accessoire ou consommable. */
final class PartsProduct extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'sku',
        'barcode',
        'name',
        'category',
        'brand',
        'unit',
        'retail_price_htg',
        'wholesale_price_htg',
        'reorder_level',
        'photo_file_id',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'retail_price_htg' => 'decimal:2',
            'wholesale_price_htg' => 'decimal:2',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(PartsProductFitment::class, 'product_id')->orderBy('make')->orderBy('model');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(PartsStockLevel::class, 'product_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'photo_file_id');
    }
}
