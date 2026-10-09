<?php

namespace App\Http\Controllers;

use App\Exceptions\EmailDeliveryUnavailable;
use App\Models\ApiAccessToken;
use App\Models\CompanyUserAccess;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\EmailOtpService;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class AuthController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $emailOtp,
        private readonly AuditLogger $audit,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $email = $this->normaliseEmail($input['email']);
        $rateLimitKey = $this->rateLimitKey('login', $email, $request);

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->audit->record(
                eventType: 'auth.login_throttled',
                metadata: ['retry_after_seconds' => RateLimiter::availableIn($rateLimitKey)],
            );

            return response()->json([
                'message' => 'Trop de tentatives. Réessayez dans quelques instants.',
            ], 429);
        }

        $user = User::query()->where('email', $email)->first();
        $passwordIsValid = $user !== null
            && $user->is_active
            && Hash::check($input['password'], $user->password);

        if (! $passwordIsValid) {
            RateLimiter::hit($rateLimitKey, 60);

            $this->audit->record(
                eventType: 'auth.login_refused',
                actorId: $user?->id,
                actorType: $user === null ? 'SYSTEM' : 'USER',
                subjectType: $user === null ? null : User::class,
                subjectId: $user?->id,
                metadata: ['reason' => 'invalid_credentials'],
            );

            return response()->json([
                'message' => 'Adresse courriel ou mot de passe incorrect.',
            ], 401);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($input['password'])])->save();
        }

        RateLimiter::clear($rateLimitKey);

        try {
            $challenge = $this->emailOtp->issue($user, EmailOtpChallenge::PURPOSE_LOGIN, $request);
        } catch (EmailDeliveryUnavailable) {
            return response()->json([
                'message' => 'Le code de sécurité ne peut pas être envoyé pour le moment. Réessayez plus tard ou contactez l’administrateur.',
            ], 503);
        }

        return response()->json([
            'two_factor_required' => true,
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ], 202);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $input = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $challenge = $this->emailOtp->consume(
            $input['challenge_id'],
            EmailOtpChallenge::PURPOSE_LOGIN,
            $input['code'],
        );

        if ($challenge === null) {
            $this->audit->record(
                eventType: 'auth.two_factor_refused',
                subjectType: EmailOtpChallenge::class,
                subjectId: $input['challenge_id'],
                metadata: ['reason' => 'invalid_or_expired_code'],
            );

            return response()->json([
                'message' => 'Le code est invalide, expiré ou déjà utilisé.',
            ], 422);
        }

        $user = $challenge->user;

        if ($user === null || ! $user->is_active) {
            $this->audit->record(
                eventType: 'auth.two_factor_refused',
                actorId: $user?->id,
                actorType: $user === null ? 'SYSTEM' : 'USER',
                subjectType: EmailOtpChallenge::class,
                subjectId: $challenge->id,
                metadata: ['reason' => 'account_inactive'],
            );

            return response()->json([
                'message' => 'Ce compte n’est pas actif.',
            ], 403);
        }

        $now = now()->utc();
        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? $now,
            'two_factor_email_enabled' => true,
            'two_factor_email_verified_at' => $now,
            'last_login_at' => $now,
        ])->save();

        [$token, $plainToken] = ApiAccessToken::issueFor($user, $request, $input['device_name'] ?? null);

        $this->audit->record(
            eventType: 'auth.login_succeeded',
            actorId: $user->id,
            actorType: 'USER',
            subjectType: ApiAccessToken::class,
            subjectId: $token->id,
            metadata: ['two_factor' => 'email'],
        );

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at->toIso8601String(),
            'user' => $this->userPayload($user),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
        ]);

        $email = $this->normaliseEmail($input['email']);
        $rateLimitKey = $this->rateLimitKey('password_reset', $email, $request);
        $challengeId = (string) Str::uuid();

        if (! RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            RateLimiter::hit($rateLimitKey, 60);

            $user = User::query()
                ->where('email', $email)
                ->where('is_active', true)
                ->first();

            if ($user !== null) {
                try {
                    $challenge = $this->emailOtp->issue($user, EmailOtpChallenge::PURPOSE_PASSWORD_RESET, $request);
                    $challengeId = $challenge->id;
                } catch (EmailDeliveryUnavailable) {
                    // La réponse reste volontairement identique pour ne pas révéler l'adresse.
                }
            }
        }

        return response()->json([
            'message' => 'Si cette adresse correspond à un compte actif, un code de réinitialisation a été envoyé.',
            'challenge_id' => $challengeId,
        ], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $input = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => PasswordPolicy::rules(),
        ]);

        $challenge = $this->emailOtp->consume(
            $input['challenge_id'],
            EmailOtpChallenge::PURPOSE_PASSWORD_RESET,
            $input['code'],
        );

        if ($challenge === null || $challenge->user === null || ! $challenge->user->is_active) {
            $this->audit->record(
                eventType: 'auth.password_reset_refused',
                subjectType: EmailOtpChallenge::class,
                subjectId: $input['challenge_id'],
                metadata: ['reason' => 'invalid_or_expired_code'],
            );

            return response()->json([
                'message' => 'Le code est invalide, expiré ou déjà utilisé.',
            ], 422);
        }

        $user = $challenge->user;
        $user->forceFill([
            'password' => Hash::make($input['password']),
            'two_factor_email_enabled' => true,
            'two_factor_email_verified_at' => now()->utc(),
        ])->save();
        ApiAccessToken::revokeAllFor($user);

        $this->audit->record(
            eventType: 'auth.password_reset_succeeded',
            actorId: $user->id,
            actorType: 'USER',
            subjectType: User::class,
            subjectId: $user->id,
        );

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé. Connectez-vous avec le nouveau mot de passe.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('clientele.access_token');

        if ($token instanceof ApiAccessToken) {
            $token->revoke();

            $this->audit->record(
                eventType: 'auth.logout_succeeded',
                actorId: $request->user()?->id,
                actorType: 'USER',
                subjectType: ApiAccessToken::class,
                subjectId: $token->id,
            );
        }

        return response()->json([], 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var \Illuminate\Support\Collection<int, CompanyUserAccess> $accesses */
        $accesses = CompanyUserAccess::query()
            ->with('company')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('company', static fn ($query) => $query->where('is_active', true))
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'user' => $this->userPayload($user),
            'companies' => $accesses
                ->filter(static fn (CompanyUserAccess $access) => $access->company !== null)
                ->map(static fn (CompanyUserAccess $access): array => [
                    'id' => $access->company_id,
                    'code' => $access->company->code,
                    'name' => $access->company->display_name,
                    'role_key' => $access->role_key,
                ])
                ->values(),
        ]);
    }

    /**
     * @return array{id: string, name: string, email: string, system_role: string, two_factor_email_verified: bool}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'system_role' => $user->system_role,
            'two_factor_email_verified' => $user->two_factor_email_verified_at !== null,
            'can_manage_exchange_rates' => $user->canManageExchangeRates(),
        ];
    }

    private function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function rateLimitKey(string $purpose, string $email, Request $request): string
    {
        return 'clientele:auth:'.$purpose.':'.hash_hmac(
            'sha256',
            $email.'|'.$request->ip(),
            (string) config('app.key'),
        );
    }
}
