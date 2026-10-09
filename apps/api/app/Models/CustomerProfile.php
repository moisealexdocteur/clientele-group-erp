<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class CustomerProfile extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'customer_identity_id',
        'customer_type',
        'display_name',
        'email',
        'phone',
        'group_contact_sharing_consent',
        'group_contact_sharing_consented_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'display_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'group_contact_sharing_consent' => 'boolean',
            'group_contact_sharing_consented_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentity::class, 'customer_identity_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(CarRentalReservation::class);
    }

    public function assignContact(?string $email, ?string $phone): void
    {
        $email = self::normalizeEmail($email);
        $phone = self::normalizePhone($phone);

        $this->email = $email;
        $this->phone = $phone;
        $this->email_search_hash = self::searchHash($email);
        $this->phone_search_hash = self::searchHash($phone);
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return Str::lower(trim($email));
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        return preg_replace('/[^0-9+]/', '', trim($phone));
    }

    public static function searchHash(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
