<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Vente au comptoir, réglée en espèces HTG dans une session de caisse ouverte. */
final class PartsSale extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'customer_profile_id',
        'id',
        'company_id',
        'site_id',
        'cash_register_id',
        'cash_session_id',
        'number',
        'price_list',
        'customer_name',
        'status',
        'currency',
        'item_count',
        'total',
        'amount_received',
        'change_given',
        'sold_by',
        'sold_at',
        'receipt_print_count',
    ];

    protected function casts(): array
    {
        return [
            'item_count' => 'integer',
            'total' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'change_given' => 'decimal:2',
            'sold_at' => 'immutable_datetime',
            'receipt_print_count' => 'integer',
        ];
    }

    public const PRICE_LISTS = ['retail', 'wholesale'];

    public function lines(): HasMany
    {
        return $this->hasMany(PartsSaleLine::class, 'sale_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }
}
