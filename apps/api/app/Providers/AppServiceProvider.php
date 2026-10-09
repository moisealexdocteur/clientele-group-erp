<?php

namespace App\Providers;

use App\Support\ReceiptSecretGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $command = $this->app->runningInConsole() ? ($_SERVER['argv'][1] ?? null) : null;

        ReceiptSecretGuard::check(
            (string) $this->app->environment(),
            (string) config('security.receipts.qr_signing_secret'),
            is_string($command) ? $command : null,
        );
    }
}
