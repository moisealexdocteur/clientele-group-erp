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
        'minimum_security_deposit_usd',
        'kilometer_plan',
        'included_km',
        'additional_km_rate',
        'driver_full_name',
        'driver_license_number',
        'driver_license_expires_at',
        'driver_license_verified_at',
        'lock_version',
        'rate_overridden',
        'driver_license_country',
        'driver_license_subdivision',
        'driver_license_front_file_id',
        'driver_license_back_file_id',
        'additional_driver_name',
        'additional_driver_license_number',
        'contract_snapshot',
        'contract_file_id',
        'contract_issued_at',
        'additional_charges',
    ];

    protected function casts(): array
    {
        return [
            'rate_overridden' => 'boolean',
            'pickup_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'pickup_location_detail' => 'encrypted',
            'dropoff_location_detail' => 'encrypted',
            'airport_pickup_fee_usd' => 'decimal:2',
            'airport_dropoff_fee_usd' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'minimum_security_deposit_usd' => 'decimal:2',
            'additional_km_rate' => 'decimal:2',
            'included_km' => 'integer',
            'driver_license_number' => 'encrypted',
            'additional_driver_name' => 'encrypted',
            'additional_driver_license_number' => 'encrypted',
            'contract_snapshot' => 'array',
            'contract_issued_at' => 'immutable_datetime',
            'additional_charges' => 'array',
            'driver_license_expires_at' => 'immutable_date',
            'driver_license_verified_at' => 'immutable_datetime',
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

    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CarRentalInvoice::class, 'reservation_id');
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
