<?php

namespace App\Support;

use App\Exceptions\EmailDeliveryUnavailable;
use App\Mail\AccessCodeMail;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class EmailOtpService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function issue(User $user, string $purpose, Request $request): EmailOtpChallenge
    {
        if (! in_array($purpose, [EmailOtpChallenge::PURPOSE_LOGIN, EmailOtpChallenge::PURPOSE_PASSWORD_RESET], true)) {
            throw new \InvalidArgumentException('Finalité de code de sécurité inconnue.');
        }

        if (! $this->isSafeMailerConfigured()) {
            $this->audit->record(
                eventType: 'auth.email_code_delivery_refused',
                actorId: $user->id,
                actorType: 'USER',
                subjectType: User::class,
                subjectId: $user->id,
                metadata: ['purpose' => $purpose, 'reason' => 'unsafe_mailer_configuration'],
            );

            throw new EmailDeliveryUnavailable();
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->utc()->addMinutes(config('security.email_codes.ttl_minutes'));

        $challenge = DB::transaction(function () use ($user, $purpose, $request, $code, $expiresAt): EmailOtpChallenge {
            EmailOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()->utc()]);

            return EmailOtpChallenge::query()->create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'max_attempts' => config('security.email_codes.max_attempts'),
                'requested_ip_address' => $request->ip(),
                'requested_user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
                'expires_at' => $expiresAt,
            ]);
        });

        try {
            Mail::to($user->email, $user->name)->send(
                new AccessCodeMail($user->name, $code, $purpose, $expiresAt),
            );
        } catch (Throwable) {
            $challenge->forceFill(['consumed_at' => now()->utc()])->save();

            $this->audit->record(
                eventType: 'auth.email_code_delivery_failed',
                actorId: $user->id,
                actorType: 'USER',
                subjectType: EmailOtpChallenge::class,
                subjectId: $challenge->id,
                metadata: ['purpose' => $purpose],
            );

            throw new EmailDeliveryUnavailable();
        }

        $this->audit->record(
            eventType: 'auth.email_code_requested',
            actorId: $user->id,
            actorType: 'USER',
            subjectType: EmailOtpChallenge::class,
            subjectId: $challenge->id,
            metadata: ['purpose' => $purpose],
        );

        return $challenge;
    }

    public function consume(string $challengeId, string $purpose, string $code): ?EmailOtpChallenge
    {
        return DB::transaction(function () use ($challengeId, $purpose, $code): ?EmailOtpChallenge {
            $challenge = EmailOtpChallenge::query()
                ->whereKey($challengeId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if ($challenge === null || ! $challenge->isAvailable()) {
                return null;
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempt_count');

                return null;
            }

            $challenge->forceFill(['consumed_at' => now()->utc()])->save();

            return $challenge;
        });
    }

    private function isSafeMailerConfigured(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return in_array((string) config('mail.default'), [
            'smtp',
            'ses',
            'postmark',
            'resend',
            'sendmail',
        ], true);
    }
}
