<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiAccessToken extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'token_hash',
        'token_prefix',
        'device_name',
        'abilities',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: self, 1: string}
     */
    public static function issueFor(User $user, Request $request, ?string $deviceName = null): array
    {
        $plainToken = 'cgpat_'.Str::random(64);
        $now = now()->utc();

        $token = static::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'token_prefix' => Str::substr($plainToken, 0, 14),
            'device_name' => Str::limit((string) $deviceName, 120, ''),
            'abilities' => [],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'last_used_at' => $now,
            'expires_at' => $now->addMinutes(config('security.access_tokens.absolute_minutes')),
        ]);

        return [$token, $plainToken];
    }

    public static function findActive(string $plainToken): ?self
    {
        if (! Str::startsWith($plainToken, 'cgpat_') || Str::length($plainToken) > 256) {
            return null;
        }

        $token = static::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now()->utc())
            ->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        return $token;
    }

    public static function revokeAllFor(User $user): void
    {
        static::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()->utc()]);
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null || $this->expires_at->isPast()) {
            return false;
        }

        $lastActivity = $this->last_used_at ?? $this->created_at;

        return $lastActivity !== null
            && $lastActivity->addMinutes(config('security.access_tokens.idle_minutes'))->isFuture();
    }

    public function registerUsage(Request $request): void
    {
        $this->forceFill([
            'last_used_at' => now()->utc(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
        ])->save();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()->utc()])->save();
        }
    }
}
