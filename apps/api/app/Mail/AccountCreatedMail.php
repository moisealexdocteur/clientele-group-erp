<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AccountCreatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $companyName,
        public readonly string $roleLabel,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre compte Clientèle Group ERP a été créé',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.account-created',
        );
    }
}
