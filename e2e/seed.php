<?php

/*
 * Recette de bout en bout : crée le propriétaire du système et affiche un
 * jeton d'accès. Base de recette uniquement ; aucune donnée réelle.
 */

use App\Models\ApiAccessToken;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

require __DIR__ . '/../apps/api/vendor/autoload.php';
$app = require __DIR__ . '/../apps/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$owner = User::query()->firstOrCreate(['email' => 'proprietaire.recette@example.test'], [
    'name' => 'Propriétaire Recette',
    'password' => Hash::make('Recette!2026-e2e'),
    'is_active' => true,
    'system_role' => 'owner',
]);
$owner->forceFill(['system_role' => 'owner', 'is_active' => true])->save();

[, $token] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'), 'Recette e2e');
fwrite(STDOUT, $token);
