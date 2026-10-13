<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Courriel d'essai du serveur de courriel, envoyé au propriétaire. */
final class TestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly CarbonInterface $sentAt,
        public readonly ?string $fromAddress,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Courriel d’essai - Clientèle Group');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test');
    }
}
