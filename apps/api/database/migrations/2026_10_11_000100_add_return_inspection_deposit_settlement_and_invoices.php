<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Version 0.6.0 : inspection de retour, frais supplémentaires validés,
 * règlement du dépôt de garantie et facture numérotée.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const ROLE_PERMISSIONS = [
        'car_rental_administrator' => ['rental.deposits.settle', 'rental.invoices.issue'],
        'car_rental_agent' => ['rental.invoices.issue'],
    ];

    public function up(): void
    {
        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            // Frais validés au retour : [{code, label, amount}] dans la devise de la réservation.
            $table->json('additional_charges')->nullable();
        });

        Schema::table('car_rental_security_deposits', function (Blueprint $table): void {
            $table->decimal('applied_amount', 14, 2)->nullable();
            $table->text('settlement_note')->nullable();
            $table->uuid('settled_by')->nullable();
        });

        Schema::create('car_rental_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->char('invoice_number', 8);
            $table->string('currency', 3);
            $table->json('snapshot');
            $table->decimal('total', 14, 2);
            $table->decimal('balance_due', 14, 2);
            $table->uuid('file_id')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'invoice_number']);
            $table->unique(['company_id', 'reservation_id']);
            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['reservation_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_reservations')
                ->restrictOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE car_rental_invoices ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE car_rental_invoices FORCE ROW LEVEL SECURITY');
            DB::unprepared(
                'CREATE POLICY car_rental_invoices_company_isolation ON car_rental_invoices '
                . "USING (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid) "
                . "WITH CHECK (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid)"
            );
        }

        $this->grantAdministratorPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('car_rental_invoices');
        Schema::table('car_rental_security_deposits', function (Blueprint $table): void {
            $table->dropColumn(['applied_amount', 'settlement_note', 'settled_by']);
        });
        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            $table->dropColumn('additional_charges');
        });
    }

    private function grantAdministratorPermissions(): void
    {
        DB::table('company_user_access')
            ->whereIn('role_key', array_keys(self::ROLE_PERMISSIONS))
            ->orderBy('id')
            ->get()
            ->each(static function (object $access): void {
                $permissions = is_string($access->permissions)
                    ? json_decode($access->permissions, true)
                    : $access->permissions;
                $permissions = is_array($permissions) ? $permissions : [];
                $allowed = array_is_list($permissions) ? $permissions : ($permissions['allow'] ?? []);

                if (in_array('*', $allowed, true)) {
                    return;
                }

                $allowed = array_values(array_unique([...$allowed, ...self::ROLE_PERMISSIONS[$access->role_key]]));

                if (array_is_list($permissions)) {
                    $permissions = $allowed;
                } else {
                    $permissions['allow'] = $allowed;
                }

                DB::table('company_user_access')
                    ->where('id', $access->id)
                    ->update(['permissions' => json_encode($permissions, JSON_THROW_ON_ERROR)]);
            });
    }
};
