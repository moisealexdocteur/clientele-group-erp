<?php

use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::prefix('v1')->group(function (): void {
    /*
     * Cette route ne divulgue aucune donnée de société, client ou employé.
     * Elle permet uniquement à la PWA de vérifier la compatibilité du socle.
     */
    Route::get('/bootstrap', BootstrapController::class)->name('api.v1.bootstrap');
});
