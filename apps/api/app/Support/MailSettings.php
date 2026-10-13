<?php

namespace App\Support;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Lecture du serveur de courriel configuré dans l'environnement du serveur.
 * Aucun mot de passe ni identifiant n'est exposé, et l'hôte est masqué.
 * La configuration elle-même ne se modifie pas depuis l'application.
 */
final class MailSettings
{
    /**
     * @return array{mailer: string, from_address: string|null, from_name: string|null, host: string|null, port: int|null, configured: bool}
     */
    public function summary(): array
    {
        $mailer = (string) config('mail.default');
        $smtp = $mailer === 'smtp';
        $host = $smtp ? (string) config('mail.mailers.smtp.host') : '';
        $from = config('mail.from.address');

        return [
            'mailer' => $mailer,
            'from_address' => is_string($from) && $from !== '' ? $from : null,
            'from_name' => is_string(config('mail.from.name')) ? config('mail.from.name') : null,
            'host' => $smtp ? $this->maskHost($host) : null,
            'port' => $smtp && is_numeric(config('mail.mailers.smtp.port')) ? (int) config('mail.mailers.smtp.port') : null,
            'configured' => $smtp && $host !== '' && is_string($from) && $from !== '',
        ];
    }

    /**
     * « smtp.hostinger.com » devient « smtp.h•••••••r.com » : assez pour
     * reconnaître le fournisseur, sans exposer l'adresse complète.
     */
    public function maskHost(string $host): ?string
    {
        $host = trim($host);
        if ($host === '') {
            return null;
        }

        $labels = explode('.', $host);
        if (count($labels) < 3) {
            return $this->maskLabel($labels[0]).(count($labels) === 2 ? '.'.$labels[1] : '');
        }

        $first = array_shift($labels);
        $last = array_pop($labels);

        return $first.'.'.implode('.', array_map(fn (string $label): string => $this->maskLabel($label), $labels)).'.'.$last;
    }

    /**
     * Message français, sans détail technique, pour un échec d'envoi.
     *
     * @return array{reason: string, message: string}
     */
    public function failure(Throwable $error): array
    {
        $text = strtolower($error->getMessage());

        $reason = match (true) {
            str_contains($text, '535') || str_contains($text, 'authenticat') || str_contains($text, 'username and password') => 'authentication',
            str_contains($text, 'certificate') || str_contains($text, 'ssl') || str_contains($text, 'tls') || str_contains($text, 'crypto') => 'encryption',
            str_contains($text, 'connection could not be established') || str_contains($text, 'timed out') || str_contains($text, 'getaddrinfo') || str_contains($text, 'refused') => 'connection',
            str_contains($text, 'sender') || str_contains($text, '550') || str_contains($text, '553') || str_contains($text, '554') => 'sender',
            $error instanceof TransportExceptionInterface => 'transport',
            default => 'unknown',
        };

        $message = match ($reason) {
            'authentication' => 'Le serveur de courriel refuse l’identifiant ou le mot de passe. Vérifiez SMTP_USERNAME et SMTP_PASSWORD sur le serveur.',
            'encryption' => 'La connexion chiffrée avec le serveur de courriel a échoué. Vérifiez le port et le chiffrement (SMTP_PORT, SMTP_SCHEME).',
            'connection' => 'Le serveur de courriel ne répond pas. Vérifiez l’hôte et le port (SMTP_HOST, SMTP_PORT).',
            'sender' => 'Le serveur de courriel refuse l’expéditeur ou le destinataire. Vérifiez SMTP_FROM_ADDRESS.',
            default => 'Le courriel d’essai n’a pas pu être envoyé. Vérifiez la configuration du serveur de courriel.',
        };

        return ['reason' => $reason, 'message' => $message];
    }

    private function maskLabel(string $label): string
    {
        $length = mb_strlen($label);
        if ($length <= 2) {
            return str_repeat('•', $length);
        }

        return mb_substr($label, 0, 1).str_repeat('•', $length - 2).mb_substr($label, -1);
    }
}
