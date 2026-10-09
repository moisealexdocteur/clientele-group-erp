<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de caisse : du fond d'ouverture à la clôture déclarée. Les
 * espèces attendues se calculent à partir des mouvements de la session.
 */
final class CashSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'site_id',
        'cash_register_id',
        'status',
        'opened_by',
        'opened_at',
        'opening_usd',
        'opening_htg',
        'closed_by',
        'closed_at',
        'expected_usd',
        'expected_htg',
        'declared_usd',
        'declared_htg',
        'variance_usd',
        'variance_htg',
        'variance_note',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'report_print_count',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'opening_usd' => 'decimal:2',
            'opening_htg' => 'decimal:2',
            'expected_usd' => 'decimal:2',
            'expected_htg' => 'decimal:2',
            'declared_usd' => 'decimal:2',
            'declared_htg' => 'decimal:2',
            'variance_usd' => 'decimal:2',
            'variance_htg' => 'decimal:2',
            'report_print_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('occurred_at');
    }
}
