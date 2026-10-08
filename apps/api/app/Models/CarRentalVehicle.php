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

    public const FUEL_TYPES = ['gasoline', 'diesel'];

    public const TRANSMISSIONS = ['manual', 'automatic'];

    public const REGISTRATION_STATUSES = [
        'demonstration',
        'location',
        'normal',
    ];

    /**
     * Images publiques de référence. Elles ne remplacent jamais les photos
     * d'inspection ni les documents du véhicule.
     *
     * @var array<string, array{key: string, url: string, label: string, source_url: string}>
     */
    public const REFERENCE_PHOTOS = [
        'nissan-frontier-aa-85177' => [
            'key' => 'nissan-frontier-aa-85177',
            'url' => '/fleet/nissan-frontier-aa-85177.jpg',
            'label' => 'Photo de référence - publication Clientèle Group',
            'source_url' => 'https://www.tiktok.com/@clientele_group/photo/7618667507980782866?lang=fr',
        ],
        'suzuki-jimny-lo-01727' => [
            'key' => 'suzuki-jimny-lo-01727',
            'url' => '/fleet/suzuki-jimny-lo-01727.jpg',
            'label' => 'Photo de référence - publication Clientèle Group',
            'source_url' => 'https://www.tiktok.com/@clientele_group/video/7679127374331481352?lang=fr',
        ],
        'great-wall-poer-dm-00849' => [
            'key' => 'great-wall-poer-dm-00849',
            'url' => '/fleet/great-wall-poer-dm-00849.jpg',
            'label' => 'Photo de référence - publication Clientèle Group',
            'source_url' => 'https://www.tiktok.com/@clientele_group/photo/7618667507980782866?lang=fr',
        ],
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
        'reference_photo_key',
        'vin',
        'latest_odometer_km',
        'daily_rate_usd',
        'minimum_security_deposit_usd',
        'is_active',
        'color',
        'fuel_type',
        'transmission',
        'engine_displacement_cc',
        'doors',
        'photo_file_id',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'latest_odometer_km' => 'integer',
            'daily_rate_usd' => 'decimal:2',
            'minimum_security_deposit_usd' => 'decimal:2',
            'is_active' => 'boolean',
            'engine_displacement_cc' => 'integer',
            'doors' => 'integer',
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

    public function photo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'photo_file_id');
    }

    public function registrationEvents(): HasMany
    {
        return $this->hasMany(CarRentalVehicleRegistrationEvent::class, 'vehicle_id');
    }

    /** @return array{key: string, url: string, label: string, source_url: string}|null */
    public function referencePhoto(): ?array
    {
        if ($this->reference_photo_key === null) {
            return null;
        }

        return self::REFERENCE_PHOTOS[$this->reference_photo_key] ?? null;
    }
}
