<?php

namespace App\Support;

use RuntimeException;

/**
 * La clé de signature des QR de reçus est obligatoire hors développement et
 * tests : l'application refuse de démarrer sans elle. La clé de
 * l'application (APP_KEY) n'est jamais utilisée à sa place.
 *
 * Le contrôle s'applique aux requêtes web et aux commandes qui démarrent un
 * service (migration au démarrage du conteneur, file, planificateur, santé).
 * Les commandes de construction d'image et d'outillage n'y sont pas soumises.
 */
final class ReceiptSecretGuard
{
    public const MINIMUM_LENGTH = 32;

    /** Commandes console qui démarrent ou vérifient un service. */
    private const STARTUP_COMMANDS = ['migrate', 'queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'health:check', 'serve', 'octane:start'];

    /** @param string|null $consoleCommand null pour une requête web */
    public static function check(string $environment, ?string $secret, ?string $consoleCommand = null, bool $console = false): void
    {
        if (in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        if ($console && ! in_array((string) $consoleCommand, self::STARTUP_COMMANDS, true)) {
            return;
        }

        if (strlen((string) $secret) < self::MINIMUM_LENGTH) {
            throw new RuntimeException(sprintf(
                'QR_SIGNING_SECRET est absent ou trop court (%d caractères au moins). L’application ne démarre pas sans clé de signature des reçus.',
                self::MINIMUM_LENGTH,
            ));
        }
    }
}
