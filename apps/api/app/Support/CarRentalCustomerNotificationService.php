<?php

namespace App\Support;

use App\Mail\CarRentalCustomerNotificationMail;
use App\Models\CarRentalReservation;
use App\Models\Company;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

final class CarRentalCustomerNotificationService
{
    public const RESERVATION_CREATED = 'reservation_created';
    public const RESERVATION_UPDATED = 'reservation_updated';
    public const CHECKED_OUT = 'checked_out';
    public const EXTENDED = 'extended';
    public const RETURN_RECORDED = 'return_recorded';
    public const SIGNED_CONTRACT_ISSUED = 'signed_contract_issued';
    public const INVOICE_ISSUED = 'invoice_issued';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Les factures et contrats signés sont fournis par leurs modules
     * respectifs. Le service accepte uniquement des PDF réels et valides ;
     * aucun document n'est créé ou annoncé par cette méthode.
     *
     * @param array<int, array{name: string, content: string, mime: string}> $pdfAttachments
     */
    public function notify(
        Company $company,
        CarRentalReservation $reservation,
        string $event,
        array $pdfAttachments = [],
    ): bool {
        if (! in_array($event, [
            self::RESERVATION_CREATED,
            self::RESERVATION_UPDATED,
            self::CHECKED_OUT,
            self::EXTENDED,
            self::RETURN_RECORDED,
            self::SIGNED_CONTRACT_ISSUED,
            self::INVOICE_ISSUED,
        ], true)) {
            throw new InvalidArgumentException('Événement de notification Car Rental non pris en charge.');
        }

        $pdfAttachments = $this->validatedPdfAttachments($pdfAttachments);

        if (in_array($event, [self::SIGNED_CONTRACT_ISSUED, self::INVOICE_ISSUED], true) && $pdfAttachments === []) {
            $this->record($company, $reservation, $event, 'skipped_no_pdf');

            return false;
        }

        $profile = $reservation->customerProfile;

        if (! $profile instanceof CustomerProfile || empty($profile->email)) {
            $this->record($company, $reservation, $event, 'skipped_no_email');

            return false;
        }

        $content = $this->contentFor($company, $reservation, $event);

        // Mise en circulation avec contrat signé : le même courriel annonce la remise et joint le contrat.
        if ($event === self::CHECKED_OUT && $pdfAttachments !== []) {
            $content['intro'] = 'Le véhicule a été remis. Votre contrat de location signé est joint à ce courriel.';
        }

        try {
            Mail::to($profile->email, $profile->display_name)->send(new CarRentalCustomerNotificationMail(
                $profile->display_name,
                $content['subject'],
                $content['heading'],
                $content['intro'],
                $reservation->formattedNumber(),
                $content['details'],
                $content['vehicle_image_url'],
                $content['vehicle_image_alt'],
                $pdfAttachments,
                [
                    'name' => $company->display_name ?: $company->legal_name,
                    'address' => $company->legal_address,
                    'phones' => $company->phone_numbers,
                    'roadside' => $company->roadside_assistance_phone,
                ],
            ));
        } catch (Throwable) {
            $this->record($company, $reservation, $event, 'failed');

            return false;
        }

        $this->record($company, $reservation, $event, 'sent', count($pdfAttachments));

        return true;
    }

    /**
     * Envoie la facture seulement si son module a fourni un PDF généré et
     * validé. Le contrat signé peut être ajouté au même envoi par le module
     * concerné, sans jamais générer un document fictif.
     *
     * @param array<int, array{name: string, content: string, mime: string}> $pdfAttachments
     */
    public function notifyInvoice(
        Company $company,
        CarRentalReservation $reservation,
        array $pdfAttachments,
    ): bool {
        $validAttachments = $this->validatedPdfAttachments($pdfAttachments);

        return $this->notify($company, $reservation, self::INVOICE_ISSUED, $validAttachments);
    }

    /**
     * Envoie le contrat seulement si son module a fourni au moins un PDF
     * signé et validé. Cette méthode n'enregistre aucune signature et ne
     * génère aucun document.
     *
     * @param array<int, array{name: string, content: string, mime: string}> $pdfAttachments
     */
    public function notifySignedContract(
        Company $company,
        CarRentalReservation $reservation,
        array $pdfAttachments,
    ): bool {
        $validAttachments = $this->validatedPdfAttachments($pdfAttachments);

        return $this->notify($company, $reservation, self::SIGNED_CONTRACT_ISSUED, $validAttachments);
    }

    /** @return array{subject: string, heading: string, intro: string, details: array<string, string>, vehicle_image_url: string, vehicle_image_alt: string} */
    private function contentFor(Company $company, CarRentalReservation $reservation, string $event): array
    {
        $vehicle = $reservation->vehicle;
        $vehicleName = trim(implode(' ', array_filter([
            $vehicle?->make,
            $vehicle?->model,
        ], static fn (?string $value): bool => filled($value))));
        $details = [
            'Véhicule' => $vehicleName !== '' ? $vehicleName : 'Véhicule de location',
            'Départ prévu' => $this->formatDate($reservation->pickup_at, $company),
            'Retour prévu' => $this->formatDate($reservation->due_at, $company),
        ];

        $vehicleCategory = $vehicle?->category;
        $imageName = match ($vehicleCategory) {
            'pickup' => 'car-rental-pickup.svg',
            'mid_suv' => 'car-rental-mid-suv.svg',
            default => 'car-rental-suv.svg',
        };
        $imageUrl = rtrim((string) config('app.url'), '/') . '/vehicle-images/' . $imageName;
        // Illustration générique de la catégorie, jamais présentée comme la
        // photo du véhicule : les photos réelles peuvent montrer la plaque.
        $imageAlt = 'Illustration de catégorie ' . match ($vehicleCategory) {
            'pickup' => 'pick-up',
            'mid_suv' => 'SUV intermédiaire',
            default => 'SUV',
        };

        $content = match ($event) {
            self::RESERVATION_UPDATED => [
                'subject' => 'Votre réservation a été mise à jour - Clientèle Group',
                'heading' => 'Votre réservation a été mise à jour',
                'intro' => 'Voici les informations à jour de votre réservation.',
            ],
            self::CHECKED_OUT => [
                'subject' => 'Votre location est en circulation - Clientèle Group',
                'heading' => 'Votre location est en circulation',
                'intro' => 'Le véhicule a été remis. Conservez cette information pour votre suivi.',
            ],
            self::EXTENDED => [
                'subject' => 'Votre location a été prolongée - Clientèle Group',
                'heading' => 'Votre location a été prolongée',
                'intro' => 'La nouvelle date de retour est indiquée ci-dessous.',
            ],
            self::RETURN_RECORDED => [
                'subject' => 'Votre retour de véhicule est enregistré - Clientèle Group',
                'heading' => 'Votre retour est enregistré',
                'intro' => 'Le retour du véhicule a été enregistré. La facture vous sera envoyée séparément.',
            ],
            self::SIGNED_CONTRACT_ISSUED => [
                'subject' => 'Votre contrat de location signé est disponible - Clientèle Group',
                'heading' => 'Votre contrat signé est disponible',
                'intro' => 'Le contrat de location signé est joint à ce courriel.',
            ],
            self::INVOICE_ISSUED => [
                'subject' => 'Votre facture est disponible - Clientèle Group',
                'heading' => 'Votre facture est disponible',
                'intro' => 'La facture de votre location est jointe à ce courriel.',
            ],
            default => [
                'subject' => 'Votre réservation est confirmée - Clientèle Group',
                'heading' => 'Votre réservation est confirmée',
                'intro' => 'Votre réservation de véhicule a été enregistrée.',
            ],
        };

        return [
            ...$content,
            'details' => $details,
            'vehicle_image_url' => $imageUrl,
            'vehicle_image_alt' => $imageAlt,
        ];
    }

    /**
     * @param array<int, array{name: string, content: string, mime: string}> $pdfAttachments
     * @return array<int, array{name: string, content: string, mime: string}>
     */
    private function validatedPdfAttachments(array $pdfAttachments): array
    {
        $validated = [];

        foreach ($pdfAttachments as $attachment) {
            $name = $attachment['name'] ?? null;
            $content = $attachment['content'] ?? null;
            $mime = $attachment['mime'] ?? null;

            if (! is_string($name) || ! is_string($content) || ! is_string($mime)) {
                continue;
            }

            $name = trim($name);

            if (
                $name === ''
                || ! str_ends_with(strtolower($name), '.pdf')
                || strtolower(trim($mime)) !== 'application/pdf'
                || ! str_starts_with($content, '%PDF-')
            ) {
                continue;
            }

            $validated[] = [
                'name' => $name,
                'content' => $content,
                'mime' => 'application/pdf',
            ];

            if (count($validated) === 2) {
                break;
            }
        }

        return $validated;
    }

    private function formatDate(?\Carbon\CarbonInterface $value, Company $company): string
    {
        if ($value === null) {
            return 'À confirmer';
        }

        $timezone = filled($company->timezone)
            ? $company->timezone
            : 'America/Port-au-Prince';

        return $value
            ->setTimezone($timezone)
            ->translatedFormat('d F Y h:i A') . ' · Cap-Haïtien, Haïti';
    }

    private function record(
        Company $company,
        CarRentalReservation $reservation,
        string $event,
        string $status,
        int $attachmentCount = 0,
    ): void {
        $this->audit->record(
            eventType: "car_rental.customer_notification_{$status}",
            companyId: $company->id,
            actorType: 'SYSTEM',
            subjectType: CarRentalReservation::class,
            subjectId: $reservation->id,
            metadata: [
                'event' => $event,
                'attachment_count' => $attachmentCount,
            ],
        );
    }
}
