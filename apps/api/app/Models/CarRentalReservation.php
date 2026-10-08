<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CarRentalReservation extends Model
{
    use HasUuids;

    public const ACTIVE_STATES = ['reserved', 'checked_out'];

    public const STATES = [
        'draft',
        'reserved',
        'checked_out',
        'completed',
        'cancelled',
    ];

    public const LOCATION_TYPES = ['site', 'cap_haitien_airport', 'custom'];

    public const KILOMETER_PLANS = ['limited', 'unlimited'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'site_id',
        'customer_profile_id',
        'vehicle_id',
        'reservation_number',
        'state',
        'pickup_at',
        'due_at',
        'checked_out_at',
        'returned_at',
        'pickup_location_type',
        'pickup_location_detail',
        'dropoff_location_type',
        'dropoff_location_detail',
        'airport_pickup_fee_usd',
        'airport_dropoff_fee_usd',
        'currency',
        'daily_rate',
        'kilometer_plan',
        'included_km',
        'additional_km_rate',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'pickup_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'pickup_location_detail' => 'encrypted',
            'dropoff_location_detail' => 'encrypted',
            'airport_pickup_fee_usd' => 'decimal:2',
            'airport_dropoff_fee_usd' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'additional_km_rate' => 'decimal:2',
            'included_km' => 'integer',
            'lock_version' => 'integer',
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

    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(CarRentalVehicle::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CarRentalPayment::class, 'reservation_id');
    }

    public function securityDeposits(): HasMany
    {
        return $this->hasMany(CarRentalSecurityDeposit::class, 'reservation_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(CarRentalInspection::class, 'reservation_id');
    }

    public function formattedNumber(): string
    {
        return substr($this->reservation_number, 0, 4) . ' ' . substr($this->reservation_number, 4, 4);
    }
}
