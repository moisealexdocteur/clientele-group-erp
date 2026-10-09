<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'system_role',
        'two_factor_email_enabled',
        'two_factor_email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_email_enabled' => 'boolean',
            'two_factor_email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            // Droit de saisir le taux du groupe, accordé par le propriétaire (jamais par affectation de masse).
            'can_manage_exchange_rates' => 'boolean',
        ];
    }

    public function companyAccesses(): HasMany
    {
        return $this->hasMany(CompanyUserAccess::class);
    }

    public function apiAccessTokens(): HasMany
    {
        return $this->hasMany(ApiAccessToken::class);
    }

    public function emailOtpChallenges(): HasMany
    {
        return $this->hasMany(EmailOtpChallenge::class);
    }

    /** Le propriétaire et les personnes qu'il désigne saisissent le taux HTG/USD du groupe. */
    public function canManageExchangeRates(): bool
    {
        return $this->system_role === 'owner' || (bool) $this->can_manage_exchange_rates;
    }
}
