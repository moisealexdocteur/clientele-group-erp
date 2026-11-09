<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lot de motocyclettes reçu du fournisseur. */
final class MotoBatch extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'site_id',
        'reference',
        'note',
        'received_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(MotoUnit::class, 'batch_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
