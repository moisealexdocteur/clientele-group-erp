<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Company extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** Valeurs de départ identiques aux valeurs par défaut de la base. */
    /** Activités prises en charge ; chacune a ses écrans et ses profils. */
    public const ACTIVITIES = ['car_rental', 'auto_parts'];

    protected $attributes = [
        'activity' => 'car_rental',
        'moto_certificate_offset_x_mm' => '0.0',
        'moto_certificate_offset_y_mm' => '0.0',
        'rental_airport_fee_usd' => '20.00',
        'rental_cleaning_fee_usd' => '20.00',
    ];

    protected $fillable = [
        'code',
        'legal_name',
        'display_name',
        'base_currency',
        'timezone',
        'timezone_display_name',
        'locale',
        'is_active',
        'legal_representative',
        'tax_identification_number',
        'legal_address',
        'phone_numbers',
        'rental_contract_terms',
        'roadside_assistance_phone',
        'rental_airport_fee_usd',
        'rental_cleaning_fee_usd',
        'activity',
        'moto_certificate_offset_x_mm',
        'moto_certificate_offset_y_mm',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rental_airport_fee_usd' => 'decimal:2',
            'rental_cleaning_fee_usd' => 'decimal:2',
            'moto_certificate_offset_x_mm' => 'decimal:1',
            'moto_certificate_offset_y_mm' => 'decimal:1',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
