<?php

use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutoParts;
use App\Http\Controllers\CarRental;
use App\Http\Controllers\Configuration;
use App\Http\Controllers\CarRentalFileController;
use App\Http\Controllers\Cash;
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

    /*
     * Taux HTG/USD du groupe : lecture pour toute personne connectée,
     * saisie par le propriétaire ou une personne désignée (contrôlée par le contrôleur).
     */
    Route::middleware('api.token')->group(function (): void {
        Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->name('api.v1.exchange-rates.index');
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])->name('api.v1.exchange-rates.store');
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
            Route::patch('/companies/{company}/sites/{site}/kind', Configuration\UpdateSiteKind::class)
                ->name('api.v1.system.configuration.sites.kind');
            Route::patch('/companies/{company}/activity', Configuration\UpdateCompanyActivity::class)
                ->name('api.v1.system.configuration.companies.activity');
            Route::patch('/companies/{company}/moto-certificate', Configuration\UpdateMotoCertificateOffset::class)
                ->name('api.v1.system.configuration.companies.moto-certificate');
            Route::post('/companies/{company}/cash-registers', [SystemConfigurationController::class, 'storeCashRegister'])
                ->name('api.v1.system.configuration.cash-registers.store');
            Route::get('/companies/{company}/users', [SystemConfigurationController::class, 'companyUsers'])
                ->name('api.v1.system.configuration.company-users.index');
            Route::post('/companies/{company}/users', [SystemConfigurationController::class, 'storeCompanyUser'])
                ->name('api.v1.system.configuration.company-users.store');
            Route::patch('/companies/{company}/users/{companyUserAccess}', [SystemConfigurationController::class, 'updateCompanyUser'])
                ->name('api.v1.system.configuration.company-users.update');
            Route::patch('/companies/{company}/users/{companyUserAccess}/exchange-rate-access', [SystemConfigurationController::class, 'updateExchangeRateAccess'])
                ->name('api.v1.system.configuration.company-users.exchange-rate-access');
            Route::patch('/companies/{company}/users/{companyUserAccess}/status', [SystemConfigurationController::class, 'updateCompanyUserStatus'])
                ->name('api.v1.system.configuration.company-users.status');
            Route::post('/companies/{company}/users/{companyUserAccess}/reset-password', [SystemConfigurationController::class, 'resetCompanyUserPassword'])
                ->name('api.v1.system.configuration.company-users.reset-password');
            Route::delete('/companies/{company}/users/{companyUserAccess}', [SystemConfigurationController::class, 'destroyCompanyUser'])
                ->name('api.v1.system.configuration.company-users.destroy');

            // Serveur de courriel : lecture seule (réglé dans l'environnement du serveur) et essai.
            Route::get('/mail', Configuration\ShowMailSettings::class)
                ->name('api.v1.system.configuration.mail.show');
            Route::post('/mail/test', Configuration\SendTestMail::class)
                ->middleware('throttle:5,10')
                ->name('api.v1.system.configuration.mail.test');
        });

    Route::middleware(['api.token', 'company.context'])->group(function (): void {
        Route::get('/context', CompanyContextController::class)->name('api.v1.context');

        /*
         * Caisse commune : ouverture, clôture, écarts et rapport journalier.
         * La lecture des caisses et sessions est contrôlée par le contrôleur
         * (cash.sessions.operate ou cash.reports.read).
         */
        Route::prefix('cash')->group(function (): void {
            Route::get('/registers', Cash\ListCashRegisters::class)->name('api.v1.cash.registers.index');
            Route::post('/registers/{register}/sessions', Cash\OpenCashSession::class)
                ->middleware('company.permission:cash.sessions.operate')
                ->name('api.v1.cash.sessions.open');
            Route::get('/sessions/{session}', Cash\ShowCashSession::class)->name('api.v1.cash.sessions.show');
            Route::post('/sessions/{session}/close', Cash\CloseCashSession::class)
                ->middleware('company.permission:cash.sessions.operate')
                ->name('api.v1.cash.sessions.close');
            Route::post('/sessions/{session}/review', Cash\ReviewCashSession::class)
                ->middleware('company.permission:cash.sessions.approve')
                ->name('api.v1.cash.sessions.review');
            Route::get('/reports/daily', Cash\ShowDailyCashReport::class)
                ->middleware('company.permission:cash.reports.read')
                ->name('api.v1.cash.reports.daily');
            Route::post('/reports/daily/exports', Cash\RecordDailyReportExport::class)
                ->middleware('company.permission:cash.reports.read')
                ->name('api.v1.cash.reports.daily.exports');
        });


        /*
         * Auto Parts et motocyclettes : catalogue de pièces, stock par magasin
         * et entrepôt, vente au comptoir en espèces HTG. Réservé aux sociétés
         * dont l'activité est Auto Parts.
         */
        Route::prefix('parts')->middleware('company.activity:auto_parts')->group(function (): void {
            Route::get('/products', AutoParts\ListProducts::class)
                ->middleware('company.permission:parts.catalog.read')
                ->name('api.v1.parts.products.index');
            Route::post('/products', AutoParts\StoreProduct::class)
                ->middleware('company.permission:parts.catalog.manage')
                ->name('api.v1.parts.products.store');
            Route::get('/products/{product}', AutoParts\ShowProduct::class)
                ->middleware('company.permission:parts.catalog.read')
                ->name('api.v1.parts.products.show');
            Route::patch('/products/{product}', AutoParts\UpdateProduct::class)
                ->middleware('company.permission:parts.catalog.manage')
                ->name('api.v1.parts.products.update');
            Route::patch('/products/{product}/active', AutoParts\UpdateProductActive::class)
                ->middleware('company.permission:parts.catalog.manage')
                ->name('api.v1.parts.products.active');
            Route::put('/products/{product}/photo', AutoParts\UpdateProductPhoto::class)
                ->middleware('company.permission:parts.catalog.manage')
                ->name('api.v1.parts.products.photo');
            Route::put('/products/{product}/fitments', AutoParts\SaveProductFitments::class)
                ->middleware('company.permission:parts.catalog.manage')
                ->name('api.v1.parts.products.fitments');
            Route::get('/stock/movements', AutoParts\ListStockMovements::class)
                ->middleware('company.permission:parts.stock.read')
                ->name('api.v1.parts.stock.movements');
            Route::post('/stock/receipts', AutoParts\ReceiveStock::class)
                ->middleware('company.permission:parts.stock.manage')
                ->name('api.v1.parts.stock.receipts');
            Route::post('/stock/adjustments', AutoParts\AdjustStock::class)
                ->middleware('company.permission:parts.stock.manage')
                ->name('api.v1.parts.stock.adjustments');
            Route::post('/stock/transfers', AutoParts\TransferStock::class)
                ->middleware('company.permission:parts.stock.manage')
                ->name('api.v1.parts.stock.transfers');
            Route::get('/sales', AutoParts\ListSales::class)
                ->middleware('company.permission:parts.sales.read')
                ->name('api.v1.parts.sales.index');
            Route::post('/sales', AutoParts\StoreSale::class)
                ->middleware('company.permission:parts.sales.create')
                ->name('api.v1.parts.sales.store');
            Route::get('/sales/{sale}', AutoParts\ShowSale::class)
                ->middleware('company.permission:parts.sales.read')
                ->name('api.v1.parts.sales.show');
            Route::post('/sales/{sale}/receipt/prints', AutoParts\RecordSaleReceiptPrint::class)
                ->middleware('company.permission:parts.sales.read')
                ->name('api.v1.parts.sales.receipt-prints');

            // Vente de motocyclettes : commandes payées d'avance, lots du fournisseur, remise.
            Route::prefix('motos')->group(function (): void {
                Route::get('/models', AutoParts\Motos\ListMotoModels::class)
                    ->middleware('company.permission:parts.motos.read')
                    ->name('api.v1.parts.motos.models.index');
                Route::post('/models', AutoParts\Motos\SaveMotoModel::class)
                    ->middleware('company.permission:parts.motos.models.manage')
                    ->name('api.v1.parts.motos.models.store');
                Route::patch('/models/{model}', AutoParts\Motos\SaveMotoModel::class)
                    ->middleware('company.permission:parts.motos.models.manage')
                    ->name('api.v1.parts.motos.models.update');
                Route::get('/units', AutoParts\Motos\ListMotoUnits::class)
                    ->middleware('company.permission:parts.motos.read')
                    ->name('api.v1.parts.motos.units.index');
                Route::post('/batches', AutoParts\Motos\ReceiveMotoBatch::class)
                    ->middleware('company.permission:parts.motos.receive')
                    ->name('api.v1.parts.motos.batches.store');
                Route::post('/allocate', AutoParts\Motos\AllocateMotos::class)
                    ->middleware('company.permission:parts.motos.assign')
                    ->name('api.v1.parts.motos.allocate');
                Route::get('/orders', AutoParts\Motos\ListMotoOrders::class)
                    ->middleware('company.permission:parts.motos.read')
                    ->name('api.v1.parts.motos.orders.index');
                Route::post('/orders', AutoParts\Motos\StoreMotoOrder::class)
                    ->middleware('company.permission:parts.motos.orders.create')
                    ->name('api.v1.parts.motos.orders.store');
                Route::get('/orders/{order}', AutoParts\Motos\ShowMotoOrder::class)
                    ->middleware('company.permission:parts.motos.read')
                    ->name('api.v1.parts.motos.orders.show');
                Route::post('/orders/{order}/verify', AutoParts\Motos\VerifyMotoPayment::class)
                    ->middleware('company.permission:parts.motos.payments.verify')
                    ->name('api.v1.parts.motos.orders.verify');
                Route::post('/orders/{order}/cancel', AutoParts\Motos\CancelMotoOrder::class)
                    ->middleware('company.permission:parts.motos.payments.verify')
                    ->name('api.v1.parts.motos.orders.cancel');
                Route::post('/orders/{order}/assign', AutoParts\Motos\AssignMotoUnit::class)
                    ->middleware('company.permission:parts.motos.assign')
                    ->name('api.v1.parts.motos.orders.assign');
                Route::post('/orders/{order}/called', AutoParts\Motos\MarkMotoOrderCalled::class)
                    ->middleware('company.permission:parts.motos.orders.create')
                    ->name('api.v1.parts.motos.orders.called');
                Route::post('/orders/{order}/deliver', AutoParts\Motos\DeliverMotoOrder::class)
                    ->middleware('company.permission:parts.motos.deliver')
                    ->name('api.v1.parts.motos.orders.deliver');
                Route::post('/orders/{order}/customs-document', AutoParts\Motos\DeliverCustomsDocument::class)
                    ->middleware('company.permission:parts.motos.deliver')
                    ->name('api.v1.parts.motos.orders.customs-document');
                Route::post('/orders/{order}/prints', AutoParts\Motos\RecordMotoDocumentPrint::class)
                    ->middleware('company.permission:parts.motos.deliver')
                    ->name('api.v1.parts.motos.orders.prints');
            });
        });

        Route::prefix('car-rental')->group(function (): void {
            Route::get('/customers', CarRental\SearchCustomers::class)
                ->middleware('company.permission:rental.reservations.create')
                ->name('api.v1.car-rental.customers.index');
            Route::get('/payments/{payment}/receipt', [ReceiptController::class, 'show'])
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.receipts.show');
            Route::post('/payments/{payment}/receipt/prints', [ReceiptController::class, 'recordPrint'])
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.receipts.prints');
            Route::get('/vehicles', CarRental\ListVehicles::class)
                ->middleware('company.permission:rental.vehicles.read')
                ->name('api.v1.car-rental.vehicles.index');
            Route::post('/vehicles', CarRental\StoreVehicle::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.store');
            Route::patch('/vehicles/{vehicle}/operational-status', CarRental\UpdateVehicleStatus::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.operational-status');
            Route::patch('/vehicles/{vehicle}/registration', CarRental\UpdateVehicleRegistration::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.registration');
            Route::patch('/vehicles/{vehicle}/commercial-terms', CarRental\UpdateVehicleCommercialTerms::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.commercial-terms');
            Route::patch('/vehicles/{vehicle}/details', CarRental\UpdateVehicleDetails::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.details');
            Route::patch('/vehicles/{vehicle}/active', CarRental\UpdateVehicleActive::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.active');
            Route::put('/vehicles/{vehicle}/photo', CarRental\UpdateVehiclePhoto::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.photo');
            Route::get('/vehicles/{vehicle}/documents', CarRental\ListVehicleDocuments::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.documents.index');
            Route::put('/vehicles/{vehicle}/documents', CarRental\SaveVehicleDocuments::class)
                ->middleware('company.permission:rental.vehicles.manage')
                ->name('api.v1.car-rental.vehicles.documents.store');
            Route::get('/calendar', CarRental\ShowCalendar::class)
                ->middleware('company.permission:rental.calendar.read')
                ->name('api.v1.car-rental.calendar');
            Route::get('/availability', CarRental\CheckAvailability::class)
                ->middleware('company.permission:rental.availability.read')
                ->name('api.v1.car-rental.availability');
            Route::post('/reservations', CarRental\StoreReservation::class)
                ->middleware('company.permission:rental.reservations.create')
                ->name('api.v1.car-rental.reservations.store');
            Route::get('/reservations', CarRental\ListReservations::class)
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.reservations.index');
            Route::get('/reservations/{reservation}', CarRental\ShowReservation::class)
                ->middleware('company.permission:rental.reservations.read')
                ->name('api.v1.car-rental.reservations.show');
            Route::patch('/reservations/{reservation}', CarRental\UpdateReservation::class)
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.update');
            Route::post('/reservations/{reservation}/notify', CarRental\NotifyReservation::class)
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.notify');
            Route::post('/reservations/{reservation}/check-out', CarRental\CheckOutReservation::class)
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.check-out');
            Route::post('/reservations/{reservation}/contract', CarRental\AttachContract::class)
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.contract');
            Route::post('/reservations/{reservation}/deposit-settlement', CarRental\SettleDeposit::class)
                ->middleware('company.permission:rental.deposits.settle')
                ->name('api.v1.car-rental.reservations.deposit-settlement');
            Route::post('/reservations/{reservation}/invoice', CarRental\IssueInvoice::class)
                ->middleware('company.permission:rental.invoices.issue')
                ->name('api.v1.car-rental.reservations.invoice');
            Route::post('/reservations/{reservation}/invoice/file', CarRental\AttachInvoiceFile::class)
                ->middleware('company.permission:rental.invoices.issue')
                ->name('api.v1.car-rental.reservations.invoice-file');
            Route::post('/reservations/{reservation}/extend', CarRental\ExtendReservation::class)
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.extend');
            Route::post('/reservations/{reservation}/return', CarRental\CompleteReturn::class)
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.return');
            Route::post('/reservations/{reservation}/cancel', CarRental\CancelReservation::class)
                ->middleware('company.permission:rental.reservations.manage')
                ->name('api.v1.car-rental.reservations.cancel');
            Route::post('/reservations/{reservation}/payments', CarRental\SubmitPayment::class)
                ->middleware('company.permission:rental.payments.submit')
                ->name('api.v1.car-rental.payments.store');
            Route::post('/files', [CarRentalFileController::class, 'store'])
                ->name('api.v1.car-rental.files.store');
            Route::get('/files/{file}', [CarRentalFileController::class, 'show'])
                ->name('api.v1.car-rental.files.show');
            Route::post('/reservations/{reservation}/payments/{payment}/approve', CarRental\ApprovePayment::class)
                ->middleware('company.permission:rental.payments.approve')
                ->name('api.v1.car-rental.payments.approve');
        });
    });
});
