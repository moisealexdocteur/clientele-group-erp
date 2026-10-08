<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class AccessCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $code,
        public readonly string $purpose,
        public readonly CarbonInterface $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre code de sécurité - Clientèle Group',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.access-code',
        );
    }

    public function purposeLabel(): string
    {
        return match ($this->purpose) {
            'password_reset' => 'réinitialiser votre mot de passe',
            default => 'ouvrir votre session',
        };
    }
}
