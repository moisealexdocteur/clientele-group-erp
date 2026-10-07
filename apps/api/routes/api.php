<?php

use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyContextController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::prefix('v1')->group(function (): void {
    /*
     * Cette route ne divulgue aucune donnée de société, client ou employé.
     * Elle permet uniquement à la PWA de vérifier la compatibilité du socle.
     */
    Route::get('/bootstrap', BootstrapController::class)->name('api.v1.bootstrap');

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/login/verify', [AuthController::class, 'verifyLogin'])->name('api.v1.auth.login.verify');
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->name('api.v1.auth.password.forgot');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->name('api.v1.auth.password.reset');

        Route::middleware('api.token')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        });
    });

    Route::middleware(['api.token', 'company.context'])->group(function (): void {
        Route::get('/context', CompanyContextController::class)->name('api.v1.context');
    });
});
