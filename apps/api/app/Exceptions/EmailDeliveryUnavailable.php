<?php

namespace App\Exceptions;

use RuntimeException;

final class EmailDeliveryUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Le courriel de sécurité ne peut pas être envoyé pour le moment.');
    }
}
