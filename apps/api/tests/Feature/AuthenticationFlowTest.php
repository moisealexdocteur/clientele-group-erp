<?php

namespace Tests\Feature;

use App\Mail\AccessCodeMail;
use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_must_validate_an_email_code_before_receiving_a_bearer_token(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'proprietaire@exemple.ht',
            'password' => Hash::make('MotDePasse!2026'),
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'MotDePasse!2026',
            'device_name' => 'Réception Car Rental',
        ]);

        $login
            ->assertStatus(202)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['challenge_id', 'expires_at']);

        $code = $this->extractLastEmailCode();
        $challengeId = $login->json('challenge_id');

        $verification = $this->postJson('/api/v1/auth/login/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'device_name' => 'Réception Car Rental',
        ]);

        $verification
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.two_factor_email_verified', true)
            ->assertJsonStructure(['token', 'expires_at']);

        $plainToken = $verification->json('token');
        $this->assertDatabaseHas('api_access_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);
        $this->assertDatabaseMissing('api_access_tokens', ['token_hash' => $plainToken]);

        $this->withToken($plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'auth.login_succeeded',
            'actor_id' => $user->id,
        ]);
    }

    public function test_password_reset_revokes_existing_sessions_and_never_puts_sensitive_values_in_audit(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'utilisateur@exemple.ht',
            'password' => Hash::make('AncienMotDePasse!2026'),
            'is_active' => true,
        ]);

        [$token] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        $forgot = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $forgot->assertStatus(202)->assertJsonStructure(['challenge_id']);
        $code = $this->extractLastEmailCode();

        $this->postJson('/api/v1/auth/password/reset', [
            'challenge_id' => $forgot->json('challenge_id'),
            'code' => $code,
            'password' => 'NouveauMotDePasse!2026',
            'password_confirmation' => 'NouveauMotDePasse!2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('NouveauMotDePasse!2026', $user->fresh()->password));
        $this->assertNotNull($token->fresh()->revoked_at);

        app(AuditLogger::class)->record('test.audit_scrub', metadata: [
            'password' => 'doit-disparaitre',
            'email' => 'utilisateur@exemple.ht',
            'nested' => [
                'access_token' => 'doit-disparaitre',
                'safe' => 'conserve',
            ],
        ]);

        $metadata = AuditEvent::query()
            ->where('event_type', 'test.audit_scrub')
            ->sole()
            ->metadata;

        $this->assertSame(['nested' => ['safe' => 'conserve']], $metadata);
    }

    public function test_password_reset_does_not_reveal_whether_an_email_exists(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'inconnu@exemple.ht',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('message', 'Si cette adresse correspond à un compte actif, un code de réinitialisation a été envoyé.')
            ->assertJsonStructure(['challenge_id']);

        Mail::assertNothingSent();
    }

    private function extractLastEmailCode(): string
    {
        $code = null;

        Mail::assertSent(AccessCodeMail::class, function (AccessCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertIsString($code);

        return $code;
    }
}
