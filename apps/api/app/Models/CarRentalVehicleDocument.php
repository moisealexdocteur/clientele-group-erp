<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CarRentalVehicleDocument extends Model
{
    use HasUuids;

    public const TYPES = [
        'registration',
        'oavct_insurance',
        'tint_permit',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'document_type',
        'document_number',
        'issued_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(CarRentalVehicle::class, 'vehicle_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
