<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use App\Models\ApiAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class SystemMailTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'mot-de-passe-smtp-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.hostinger.com',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.username' => 'no-reply@clientelegroup.tech',
            'mail.mailers.smtp.password' => self::PASSWORD,
            'mail.from.address' => 'no-reply@clientelegroup.tech',
            'mail.from.name' => 'Clientèle Group',
        ]);
    }

    public function test_the_owner_sees_the_sender_without_the_password_or_the_full_host(): void
    {
        [$owner, $token] = $this->owner();

        $response = $this->withToken($token)
            ->getJson('/api/v1/system/configuration/mail')
            ->assertOk()
            ->assertJsonPath('data.from_address', 'no-reply@clientelegroup.tech')
            ->assertJsonPath('data.from_name', 'Clientèle Group')
            ->assertJsonPath('data.host', 'smtp.h•••••••r.com')
            ->assertJsonPath('data.port', 465)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.test_recipient', $owner->email);

        self::assertStringNotContainsString(self::PASSWORD, $response->getContent());
        self::assertStringNotContainsString('smtp.hostinger.com', $response->getContent());
        self::assertStringNotContainsString('username', $response->getContent());
    }

    public function test_the_owner_sends_a_test_mail_to_their_own_address_only(): void
    {
        Mail::fake();
        [$owner, $token] = $this->owner();

        $this->withToken($token)
            ->postJson('/api/v1/system/configuration/mail/test', ['to' => 'autre@example.test'])
            ->assertOk()
            ->assertJsonPath('message', "Courriel d’essai envoyé à {$owner->email}. Vérifiez la boîte de réception et les indésirables.");

        Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo($owner->email) && ! $mail->hasTo('autre@example.test'));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'configuration.mail_test_sent', 'actor_id' => $owner->id]);
    }

    public function test_an_smtp_failure_is_explained_in_french_without_a_stack_trace(): void
    {
        // Port fermé : la connexion est refusée tout de suite.
        config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        [$owner, $token] = $this->owner();

        $response = $this->withToken($token)
            ->postJson('/api/v1/system/configuration/mail/test')
            ->assertStatus(502)
            ->assertJsonPath('reason', 'connection')
            ->assertJsonPath('message', 'Le serveur de courriel ne répond pas. Vérifiez l’hôte et le port (SMTP_HOST, SMTP_PORT).')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');

        self::assertStringNotContainsString(self::PASSWORD, $response->getContent());
        self::assertStringNotContainsString('stream_socket_client', $response->getContent());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'configuration.mail_test_failed', 'actor_id' => $owner->id]);
    }

    public function test_only_the_owner_reaches_the_mail_settings(): void
    {
        Mail::fake();
        $agent = User::factory()->create(['is_active' => true]);
        [, $token] = ApiAccessToken::issueFor($agent, Request::create('/api/v1/auth/login', 'POST'));

        $this->withToken($token)->getJson('/api/v1/system/configuration/mail')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/system/configuration/mail/test')->assertForbidden();
        Mail::assertNothingSent();
    }

    /** @return array{0: User, 1: string} */
    private function owner(): array
    {
        $owner = User::factory()->create(['is_active' => true, 'system_role' => 'owner']);
        [, $token] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));

        return [$owner, $token];
    }
}
