<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class TestSmtpCommand extends Command
{
    protected $signature = 'system:test-smtp
                            {email : Adresse qui recevra le message de test}';

    protected $description = 'Teste l’envoi SMTP sans afficher le mot de passe configuré';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $validation = Validator::make([
            'email' => $email,
        ], [
            'email' => ['required', 'email:filter', 'max:255'],
        ]);

        if ($validation->fails()) {
            $this->error($validation->errors()->first());

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $port = (string) config('mail.mailers.smtp.port');
        $username = (string) config('mail.mailers.smtp.username');
        $password = (string) config('mail.mailers.smtp.password');

        $this->line('Configuration SMTP');
        $this->line('Mailer : '.$mailer);
        $this->line('Hôte : '.$host);
        $this->line('Port : '.$port);
        $this->line('Identifiant configuré : '.($username !== '' ? 'oui' : 'non'));
        $this->line('Mot de passe configuré : '.($password !== '' ? 'oui' : 'non'));

        if (! in_array($mailer, ['smtp', 'ses', 'postmark', 'resend', 'sendmail'], true)) {
            $this->error('Le service de courriel n’est pas configuré pour un envoi sécurisé.');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'Test d’envoi Clientèle Group ERP.',
                static function ($message) use ($email): void {
                    $message
                        ->to($email)
                        ->subject('Clientèle Group ERP — test SMTP');
                },
            );
        } catch (Throwable $error) {
            report($error);

            $detail = $error->getMessage();
            if ($password !== '') {
                $detail = str_replace($password, '[masqué]', $detail);
            }

            $this->error('Échec de l’envoi SMTP.');
            $this->line('Détail : '.$detail);
            $this->line('Vérifiez le serveur, le port, le chiffrement et le mot de passe d’application.');

            return self::FAILURE;
        }

        $this->info('Message de test envoyé à '.$email.'.');

        return self::SUCCESS;
    }
}
