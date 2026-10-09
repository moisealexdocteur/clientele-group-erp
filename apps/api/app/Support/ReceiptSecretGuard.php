<?php

namespace App\Support;

use RuntimeException;

/**
 * La clé de signature des QR de reçus est obligatoire hors développement et
 * tests : l'application refuse de démarrer sans elle. La clé de
 * l'application (APP_KEY) n'est jamais utilisée à sa place.
 */
final class ReceiptSecretGuard
{
    public const MINIMUM_LENGTH = 32;

    /** Commandes de construction d'image, exécutées sans configuration. */
    private const BUILD_COMMANDS = ['package:discover'];

    public static function check(string $environment, ?string $secret, ?string $consoleCommand = null): void
    {
        if (in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        if ($consoleCommand !== null && in_array($consoleCommand, self::BUILD_COMMANDS, true)) {
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
