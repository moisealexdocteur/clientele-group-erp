<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fichier privé d'une société : reçu de virement, photo de véhicule,
 * pièce de location. Le nom d'origine n'est jamais conservé, car il peut
 * contenir des données personnelles.
 */
final class StoredFile extends Model
{
    use HasUuids;

    public const PURPOSE_PAYMENT_PROOF = 'payment_proof';
    public const PURPOSE_VEHICLE_PHOTO = 'vehicle_photo';
    public const PURPOSE_DRIVER_LICENSE_FRONT = 'driver_license_front';
    public const PURPOSE_DRIVER_LICENSE_BACK = 'driver_license_back';
    public const PURPOSE_INSPECTION_PHOTO = 'inspection_photo';
    public const PURPOSE_SIGNATURE = 'signature';
    public const PURPOSE_RENTAL_CONTRACT = 'rental_contract';

    public const PURPOSES = [
        self::PURPOSE_PAYMENT_PROOF,
        self::PURPOSE_VEHICLE_PHOTO,
        self::PURPOSE_DRIVER_LICENSE_FRONT,
        self::PURPOSE_DRIVER_LICENSE_BACK,
        self::PURPOSE_INSPECTION_PHOTO,
        self::PURPOSE_SIGNATURE,
        self::PURPOSE_RENTAL_CONTRACT,
    ];

    /** Fichiers qui contiennent des données d'identité ou bancaires. */
    public const SENSITIVE_PURPOSES = [
        self::PURPOSE_PAYMENT_PROOF,
        self::PURPOSE_DRIVER_LICENSE_FRONT,
        self::PURPOSE_DRIVER_LICENSE_BACK,
        self::PURPOSE_SIGNATURE,
        self::PURPOSE_RENTAL_CONTRACT,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'site_id',
        'purpose',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function isSensitive(): bool
    {
        return in_array($this->purpose, self::SENSITIVE_PURPOSES, true);
    }

    /** Adresse de lecture authentifiée, servie par CarRentalFileController::show. */
    public function apiUrl(): string
    {
        return '/api/v1/car-rental/files/' . $this->id;
    }
}
