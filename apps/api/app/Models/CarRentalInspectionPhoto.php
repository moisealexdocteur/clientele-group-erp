<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CarRentalInspectionPhoto extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'inspection_id',
        'storage_key',
        'sha256',
        'caption',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'caption' => 'encrypted',
            'captured_at' => 'immutable_datetime',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(CarRentalInspection::class, 'inspection_id');
    }
}
