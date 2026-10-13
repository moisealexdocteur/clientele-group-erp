<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serveur de courriel en vigueur, en lecture seule pour le propriétaire :
 * expéditeur, hôte masqué et port. Ni identifiant ni mot de passe.
 */
final class ShowMailSettings extends Controller
{
    public function __construct(private readonly MailSettings $settings)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->settings->summary(),
                'test_recipient' => $request->user()?->email,
            ],
        ]);
    }
}
