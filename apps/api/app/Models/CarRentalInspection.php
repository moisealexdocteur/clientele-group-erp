<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CarRentalInspection extends Model
{
    use HasUuids;

    public const STAGES = ['pre_rental', 'post_rental'];

    public const STATUSES = ['draft', 'finalized'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'reservation_id',
        'vehicle_id',
        'inspector_user_id',
        'stage',
        'status',
        'inspected_at',
        'odometer_km',
        'fuel_level_percent',
        'damage_sketch',
        'notes',
        'customer_signed_at',
        'company_signed_at',
        'customer_signature_sha256',
        'company_signature_sha256',
        'accessories',
        'photo_file_ids',
        'company_signer_name',
        'customer_signature_file_id',
        'company_signature_file_id',
    ];

    /** Accessoires contrôlés à la remise du véhicule. */
    public const ACCESSORIES = [
        'spare_tire',
        'jack',
        'wheel_wrench',
        'warning_triangle',
        'first_aid_kit',
        'fire_extinguisher',
        'vehicle_documents',
        'floor_mats',
        'radio',
        'phone_charger',
    ];

    /** Niveaux de carburant de la fiche de sortie, en pourcentage. */
    public const FUEL_LEVELS = [10, 25, 50, 75, 100];

    protected function casts(): array
    {
        return [
            'inspected_at' => 'immutable_datetime',
            'odometer_km' => 'integer',
            'fuel_level_percent' => 'decimal:2',
            'damage_sketch' => 'array',
            'accessories' => 'array',
            'photo_file_ids' => 'array',
            'notes' => 'encrypted',
            'customer_signed_at' => 'immutable_datetime',
            'company_signed_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CarRentalReservation::class, 'reservation_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(CarRentalVehicle::class, 'vehicle_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(CarRentalInspectionPhoto::class, 'inspection_id');
    }
}
