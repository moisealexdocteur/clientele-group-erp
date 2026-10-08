<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CarRentalVehicleRegistrationEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'previous_registration_number',
        'current_registration_number',
        'previous_registration_status',
        'current_registration_status',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'immutable_datetime',
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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
