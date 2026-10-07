<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailOtpChallenge extends Model
{
    use HasUuids;

    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'purpose',
        'code_hash',
        'attempt_count',
        'max_attempts',
        'requested_ip_address',
        'requested_user_agent',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAvailable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempt_count < $this->max_attempts;
    }
}
