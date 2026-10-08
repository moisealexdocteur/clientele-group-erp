<?php

use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarRentalController;
use App\Http\Controllers\CarRentalFileController;
use App\Http\Controllers\CompanyContextController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SystemConfigurationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::prefix('v1')->group(function (): void {
    /*
     * Cette route ne divulgue aucune donnée de société, client ou employé.
     * Elle permet uniquement à la PWA de vérifier la compatibilité du socle.
     */
    Route::get('/bootstrap', BootstrapController::class)->name('api.v1.bootstrap');

    /*
     * Vérification publique du QR d'un reçu : montant, date et état
     * seulement, jamais de donnée client. Limitée par adresse IP.
     */
    Route::get('/public/receipts/{companyCode}/{number}', [ReceiptController::class, 'verify'])
        ->name('api.v1.public.receipts.verify');

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

    Route::middleware(['api.token', 'system.owner'])
        ->prefix('system/configuration')
        ->group(function (): void {
            Route::get('/companies', [SystemConfigurationController::class, 'companies'])
                ->name('api.v1.system.configuration.companies.index');
            Route::post('/companies', [SystemConfigurationController::class, 'storeCompany'])
                ->name('api.v1.system.configuration.companies.store');
            Route::patch('/companies/{company}', [SystemConfigurationController::class, 'updateCompany'])
                ->name('api.v1.system.configuration.companies.update');
            Route::post('/companies/{company}/sites', [SystemConfigurationController::class, 'storeSite'])
                ->name('api.v1.system.configuration.sites.store');
            Route::post('/companies/{company}/cash-registers', [SystemConfigurationController::class, 'storeCashRegister'])
                ->name('api.v1.system.configuration.cash-registers.store');
            Route::get('/companies/{company}/users', [SystemConfigurationController::class, 'companyUsers'])
                ->name('api.v1.system.configuration.company-users.index');
            Route::post('/companies/{company}/users', [SystemConfigurationController::class, 'storeCompanyUser'])
                ->name('api.v1.system.configuration.company-users.store');
            Route::patch('/companies/{company}/users/{companyUserAccess}', [SystemConfigurationController::class, 'updateCompanyUser'])
                ->name('api.v1.system.configuration.company-users.update');
            Route::patch('/companies/{company}/users/{companyUserAccess}/status', [SystemConfigurationController::class, 'updateCompanyUserStatus'])
                ->name('api.v1.system.configuration.company-users.status');
            Route::post('/companies/{company}/users/{companyUserAccess}/reset-password', [SystemConfigurationController::class, 'resetCompanyUserPassword'])
                ->name('api.v1.system.configuration.company-users.reset-password');
            Route::delete('/companies/{company}/users/{companyUserAccess}', [SystemConfigurationController::class, 'destroyCompanyUser'])
                ->name('api.v1.system.configuration.company-users.destroy');
        });

    Route::middleware(['api.token', 'company.context'])->group(function (): void {
        Route::get('/context', CompanyContextController::class)->name('api.v1.context');

        Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->name('api.v1.exchange-rates.index');
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])
            ->middleware('company.permission:finance.rates.manage')
            ->name('api.v1.exchange-rates.store');

        Route::prefix('car-rental')->group(function (): void {
            Route::get('/payments/{payment}/receipt', [ReceiptController::class, 'show'])
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.receipts.show');
            Route::post('/payments/{payment}/receipt/prints', [ReceiptController::class, 'recordPrint'])
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.receipts.prints');
            Route::get('/vehicles', [CarRentalController::class, 'vehicles'])
                ->middleware('company.permission:rental.vehicles.read')
                ->name('api.v1.car-rental.vehicles.index');
            Route::post('/vehicles', [CarRentalController::class, 'storeVehicle'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.store');
            Route::patch('/vehicles/{vehicle}/operational-status', [CarRentalController::class, 'updateVehicleStatus'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.operational-status');
            Route::patch('/vehicles/{vehicle}/registration', [CarRentalController::class, 'updateVehicleRegistration'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.registration');
            Route::patch('/vehicles/{vehicle}/commercial-terms', [CarRentalController::class, 'updateVehicleCommercialTerms'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.commercial-terms');
            Route::patch('/vehicles/{vehicle}/details', [CarRentalController::class, 'updateVehicleDetails'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.details');
            Route::patch('/vehicles/{vehicle}/active', [CarRentalController::class, 'updateVehicleActive'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.active');
            Route::put('/vehicles/{vehicle}/photo', [CarRentalController::class, 'updateVehiclePhoto'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.photo');
            Route::get('/vehicles/{vehicle}/documents', [CarRentalController::class, 'vehicleDocuments'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.documents.index');
            Route::put('/vehicles/{vehicle}/documents', [CarRentalController::class, 'saveVehicleDocuments'])
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.documents.store');
            Route::get('/calendar', [CarRentalController::class, 'calendar'])
                ->middleware('company.permission:rental.calendar.read')
                ->name('api.v1.car-rental.calendar');
            Route::get('/availability', [CarRentalController::class, 'availability'])
                ->middleware('company.permission:rental.availability.read')
                ->name('api.v1.car-rental.availability');
            Route::post('/reservations', [CarRentalController::class, 'store'])
                ->middleware('company.permission:rental.reservations.create')
                ->name('api.v1.car-rental.reservations.store');
            Route::get('/reservations', [CarRentalController::class, 'reservations'])
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.reservations.index');
            Route::get('/reservations/{reservation}', [CarRentalController::class, 'show'])
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.reservations.show');
            Route::patch('/reservations/{reservation}', [CarRentalController::class, 'updateReservation'])
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.update');
            Route::post('/reservations/{reservation}/notify', [CarRentalController::class, 'notifyReservation'])
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.notify');
            Route::post('/reservations/{reservation}/check-out', [CarRentalController::class, 'checkOutReservation'])
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.check-out');
            Route::post('/reservations/{reservation}/contract', [CarRentalController::class, 'attachContract'])
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.contract');
            Route::post('/reservations/{reservation}/deposit-settlement', [CarRentalController::class, 'settleDeposit'])
                ->middleware('company.permission:rental.deposits.settle')
                ->name('api.v1.car-rental.reservations.deposit-settlement');
            Route::post('/reservations/{reservation}/invoice', [CarRentalController::class, 'issueInvoice'])
                ->middleware('company.permission:rental.invoices.issue')
                ->name('api.v1.car-rental.reservations.invoice');
            Route::post('/reservations/{reservation}/invoice/file', [CarRentalController::class, 'attachInvoiceFile'])
                ->middleware('company.permission:rental.invoices.issue')
                ->name('api.v1.car-rental.reservations.invoice-file');
            Route::post('/reservations/{reservation}/extend', [CarRentalController::class, 'extendReservation'])
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.extend');
            Route::post('/reservations/{reservation}/return', [CarRentalController::class, 'completeReturn'])
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.return');
            Route::post('/reservations/{reservation}/cancel', [CarRentalController::class, 'cancelReservation'])
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.cancel');
            Route::post('/reservations/{reservation}/payments', [CarRentalController::class, 'submitPayment'])
                ->middleware('company.permission:rental.payments.submit')
                ->name('api.v1.car-rental.payments.store');
            Route::post('/files', [CarRentalFileController::class, 'store'])
                ->name('api.v1.car-rental.files.store');
            Route::get('/files/{file}', [CarRentalFileController::class, 'show'])
                ->name('api.v1.car-rental.files.show');
            Route::post('/reservations/{reservation}/payments/{payment}/approve', [CarRentalController::class, 'approvePayment'])
                ->middleware('company.permission:rental.payments.approve')
                ->name('api.v1.car-rental.payments.approve');
        });
    });
});
