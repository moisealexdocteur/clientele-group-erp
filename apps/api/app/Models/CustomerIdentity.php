<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identifiant maître volontairement non nominatif.
 * Les données de contact vivent seulement dans CustomerProfile, par société.
 */
final class CustomerIdentity extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function profiles(): HasMany
    {
        return $this->hasMany(CustomerProfile::class);
    }
}
