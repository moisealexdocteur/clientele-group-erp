<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ProvisionOwnerCommand extends Command
{
    protected $signature = 'identity:provision-owner
                            {email : Adresse personnelle du propriétaire}
                            {--name= : Nom complet du propriétaire}';

    protected $description = 'Crée le premier compte propriétaire sans exposer son mot de passe';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Nom complet du propriétaire')));

        $identityValidation = Validator::make([
            'email' => $email,
            'name' => $name,
        ], [
            'email' => ['required', 'email:filter', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($identityValidation->fails()) {
            $this->error($identityValidation->errors()->first());

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('Un compte existe déjà pour cette adresse.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Mot de passe initial (12 caractères ou plus)');
        $passwordConfirmation = (string) $this->secret('Confirmez le mot de passe initial');

        $passwordValidation = Validator::make([
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'password' => PasswordPolicy::rules(),
        ]);

        if ($passwordValidation->fails()) {
            $this->error($passwordValidation->errors()->first());

            return self::FAILURE;
        }

        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
            'system_role' => 'owner',
            'two_factor_email_enabled' => true,
        ])->save();

        $this->audit->record(
            eventType: 'identity.owner_provisioned',
            actorId: $user->id,
            actorType: 'SYSTEM',
            subjectType: User::class,
            subjectId: $user->id,
            metadata: ['system_role' => 'owner'],
        );

        $this->info('Compte propriétaire créé. La première connexion demandera un code envoyé à son courriel personnel.');

        return self::SUCCESS;
    }
}
