<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CarRentalVehicle extends Model
{
    use HasUuids;

    public const CATEGORIES = ['suv', 'mid_suv', 'pickup'];

    public const OPERATIONAL_STATUSES = [
        'available',
        'preparation',
        'washing',
        'garage',
        'in_circulation',
    ];

    public const REGISTRATION_STATUSES = [
        'demonstration',
        'official',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'site_id',
        'code',
        'category',
        'operational_status',
        'make',
        'model',
        'model_year',
        'registration_number',
        'registration_status',
        'vin',
        'latest_odometer_km',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'latest_odometer_km' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(CarRentalReservation::class, 'vehicle_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CarRentalVehicleDocument::class, 'vehicle_id');
    }

    public function registrationEvents(): HasMany
    {
        return $this->hasMany(CarRentalVehicleRegistrationEvent::class, 'vehicle_id');
    }
}
