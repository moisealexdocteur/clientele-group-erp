<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie un courriel d'essai au propriétaire, à sa propre adresse
 * seulement. Un échec est expliqué en français, sans trace technique,
 * et journalisé avec sa catégorie.
 */
final class SendTestMail extends Controller
{
    public function __construct(
        private readonly MailSettings $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User && $owner->system_role === 'owner', 403, 'Action réservée au propriétaire.');

        $summary = $this->settings->summary();
        $sentAt = now();

        try {
            Mail::to($owner->email, $owner->name)->send(new TestMail($owner->name, $sentAt, $summary['from_address']));
        } catch (Throwable $error) {
            $failure = $this->settings->failure($error);
            report($error);
            $this->audit->record(
                eventType: 'configuration.mail_test_failed',
                actorId: $owner->id,
                actorType: 'USER',
                metadata: ['reason' => $failure['reason'], 'mailer' => $summary['mailer']],
            );

            return response()->json(['message' => $failure['message'], 'reason' => $failure['reason']], 502);
        }

        $this->audit->record(
            eventType: 'configuration.mail_test_sent',
            actorId: $owner->id,
            actorType: 'USER',
            metadata: ['mailer' => $summary['mailer']],
        );

        return response()->json([
            'message' => "Courriel d’essai envoyé à {$owner->email}. Vérifiez la boîte de réception et les indésirables.",
            'sent_at' => $sentAt->toIso8601String(),
        ]);
    }
}
