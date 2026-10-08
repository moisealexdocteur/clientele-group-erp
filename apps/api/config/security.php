<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sessions API de l'ERP
    |--------------------------------------------------------------------------
    |
    | Les jetons opaques servent aux postes PWA et ne sont jamais conservés en
    | clair en base. Une durée absolue et une durée d'inactivité limitent le
    | risque d'utilisation d'un poste laissé ouvert.
    |
    */

    'access_tokens' => [
        'absolute_minutes' => (int) env('AUTH_TOKEN_ABSOLUTE_MINUTES', 720),
        'idle_minutes' => (int) env('AUTH_TOKEN_IDLE_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Codes temporaires envoyés par courriel
    |--------------------------------------------------------------------------
    */

    'email_codes' => [
        'ttl_minutes' => (int) env('AUTH_EMAIL_CODE_TTL_MINUTES', 10),
        'max_attempts' => (int) env('AUTH_EMAIL_CODE_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reçus numérotés
    |--------------------------------------------------------------------------
    |
    | Le QR d'un reçu porte une signature HMAC. Sans secret dédié, la clé de
    | l'application est utilisée afin que les tests restent autonomes.
    |
    */

    'receipts' => [
        'qr_signing_secret' => (string) env('QR_SIGNING_SECRET', ''),
    ],

];
