<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Algorithme de hachage
    |--------------------------------------------------------------------------
    |
    | Argon2id est le standard retenu pour les mots de passe Clientèle Group.
    | Laravel vérifie aussi que le hash soumis correspond bien à cet algorithme.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => env('HASH_REHASH_ON_LOGIN', true),

];
