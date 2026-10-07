<?php

namespace App\Support;

use App\Models\AuditEvent;
use Illuminate\Support\Str;

/**
 * Le journal ne conserve jamais un mot de passe, jeton ou donnée bancaire.
 * Les opérations de société doivent déjà être dans CompanyContext::within().
 */
final class AuditLogger
{
    /** @var array<int, string> */
    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'secret',
        'authorization',
        'cookie',
        'code',
        'otp',
        'email',
        'phone',
        'card_number',
        'cvv',
    ];

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $eventType,
        ?string $companyId = null,
        ?string $actorId = null,
        string $actorType = 'SYSTEM',
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $metadata = [],
    ): AuditEvent {
        $request = request();

        return AuditEvent::query()->create([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'request_id' => $request?->attributes->get('clientele.request_id') ?? (string) Str::uuid(),
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 512, ''),
            'metadata' => $this->scrub($metadata),
            'occurred_at' => now()->utc(),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function scrub(array $metadata): array
    {
        $scrubbed = [];

        foreach ($metadata as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $value = $this->scrub($value);
            }

            $scrubbed[$key] = $value;
        }

        return $scrubbed;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = Str::lower($key);

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (Str::contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
