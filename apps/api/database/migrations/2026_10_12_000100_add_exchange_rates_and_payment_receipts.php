<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Version 0.7.0 : taux HTG/USD manuels avec référence BRH, conversion des
 * paiements dans une autre devise et reçus numérotés sur huit chiffres.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const ROLE_PERMISSIONS = [
        'car_rental_administrator' => ['finance.rates.manage'],
    ];

    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // Nombre de gourdes pour un dollar américain.
            $table->decimal('rate_htg_per_usd', 12, 4);
            $table->decimal('brh_reference_rate', 12, 4)->nullable();
            $table->date('brh_reference_date')->nullable();
            $table->boolean('below_brh')->default(false);
            $table->text('note')->nullable();
            $table->uuid('set_by')->nullable();
            $table->timestampTz('effective_at');
            $table->timestampsTz();

            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'effective_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('set_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('car_rental_payments', function (Blueprint $table): void {
            // Taux appliqué quand la devise du paiement diffère de celle de la réservation.
            $table->decimal('exchange_rate_htg_per_usd', 12, 4)->nullable();
            $table->decimal('amount_in_reservation_currency', 14, 2)->nullable();
            $table->char('receipt_number', 8)->nullable();
            $table->timestampTz('receipt_issued_at')->nullable();
            $table->unsignedSmallInteger('receipt_print_count')->default(0);

            $table->unique(['company_id', 'receipt_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE exchange_rates ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE exchange_rates FORCE ROW LEVEL SECURITY');
            DB::unprepared(
                'CREATE POLICY exchange_rates_company_isolation ON exchange_rates '
                . "USING (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid) "
                . "WITH CHECK (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid)"
            );
        }

        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::table('car_rental_payments', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'receipt_number']);
            $table->dropColumn([
                'exchange_rate_htg_per_usd',
                'amount_in_reservation_currency',
                'receipt_number',
                'receipt_issued_at',
                'receipt_print_count',
            ]);
        });
        Schema::dropIfExists('exchange_rates');
    }

    private function grantPermissions(): void
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
