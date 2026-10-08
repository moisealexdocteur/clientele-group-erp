<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->string('registration_status', 16)->default('official')->after('registration_number');
        });

        /*
         * Les véhicules créés avant cette version conservent leur code comme
         * plaque en cours lorsqu'aucune plaque distincte n'avait été saisie.
         * Les véhicules anciens dont le code diffère de la plaque ne sont pas
         * modifiés automatiquement : le responsable les corrige explicitement.
         */
        $backfill = static function (string $companyId): void {
            DB::table('car_rental_vehicles')
                ->where('company_id', $companyId)
                ->where(static function ($query): void {
                    $query->whereNull('registration_number')->orWhere('registration_number', '');
                })
                ->update(['registration_number' => DB::raw('code')]);
        };

        if (DB::getDriverName() === 'pgsql') {
            foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
                DB::transaction(function () use ($companyId, $backfill): void {
                    DB::select("select set_config('app.current_company_id', ?, true)", [$companyId]);
                    $backfill($companyId);
                });
            }
        } else {
            foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
                $backfill($companyId);
            }
        }

        Schema::create('car_rental_vehicle_registration_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('vehicle_id');
            $table->string('previous_registration_number', 64);
            $table->string('current_registration_number', 64);
            $table->string('previous_registration_status', 16);
            $table->string('current_registration_status', 16);
            $table->uuid('changed_by')->nullable();
            $table->timestampTz('changed_at')->useCurrent();
            $table->timestampsTz();

            $table->index(['company_id', 'vehicle_id', 'changed_at']);
            $table->foreign(['vehicle_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_vehicles')
                ->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('car_rental_vehicle_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('vehicle_id');
            $table->string('document_type', 32);
            $table->string('document_number', 100)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['vehicle_id', 'document_type']);
            $table->index(['company_id', 'vehicle_id', 'expires_at']);
            $table->foreign(['vehicle_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_vehicles')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'car_rental_vehicle_registration_events',
                'car_rental_vehicle_documents',
            ] as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::unprepared(
                    "CREATE POLICY {$table}_company_isolation ON {$table} "
                    . "USING (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid) "
                    . "WITH CHECK (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid)"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('car_rental_vehicle_documents');
        Schema::dropIfExists('car_rental_vehicle_registration_events');

        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn('registration_status');
        });
    }
};
