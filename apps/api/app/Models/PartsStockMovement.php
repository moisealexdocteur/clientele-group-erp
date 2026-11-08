<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne du journal de stock, jamais modifiée : une erreur se corrige par un ajustement. */
final class PartsStockMovement extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'product_id',
        'site_id',
        'kind',
        'quantity',
        'balance_after',
        'unit_cost_htg',
        'reference',
        'note',
        'transfer_id',
        'sale_id',
        'recorded_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'unit_cost_htg' => 'decimal:2',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public const KINDS = ['receipt', 'adjustment', 'transfer_out', 'transfer_in', 'sale'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PartsProduct::class, 'product_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
