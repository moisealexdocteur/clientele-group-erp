<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taux manuel HTG/USD du groupe, réglé dans Configuration. Chaque saisie
 * crée une nouvelle ligne : l'historique n'est jamais modifié.
 */
final class ExchangeRate extends Model
{
    use HasUuids;

    protected $table = 'group_exchange_rates';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'rate_htg_per_usd',
        'brh_reference_rate',
        'brh_reference_date',
        'below_brh',
        'note',
        'set_by',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_htg_per_usd' => 'decimal:4',
            'brh_reference_rate' => 'decimal:4',
            'brh_reference_date' => 'immutable_date',
            'below_brh' => 'boolean',
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
