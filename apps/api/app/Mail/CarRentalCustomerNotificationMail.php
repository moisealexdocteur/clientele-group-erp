<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * @phpstan-type PdfAttachment array{name: string, content: string, mime: string}
 */
final class CarRentalCustomerNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var array<int, array{name: string, content: string, mime: string}> */
    public readonly array $pdfAttachments;

    /**
     * @param array<int, array{name: string, content: string, mime: string}> $pdfAttachments
     * @param array{name?: string|null, address?: string|null, phones?: string|null, roadside?: string|null} $contact
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly string $intro,
        public readonly string $reservationNumber,
        public readonly array $details,
        public readonly ?string $vehicleImageUrl = null,
        public readonly ?string $vehicleImageAlt = null,
        array $pdfAttachments = [],
        /** Coordonnées de la société et assistance routière, en pied de page. */
        public readonly array $contact = [],
    ) {
        $this->pdfAttachments = $pdfAttachments;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.car-rental-customer-notification');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            static fn (array $attachment): Attachment => Attachment::fromData(
                static fn (): string => $attachment['content'],
                $attachment['name'],
            )->withMime($attachment['mime']),
            $this->pdfAttachments,
        );
    }
}
