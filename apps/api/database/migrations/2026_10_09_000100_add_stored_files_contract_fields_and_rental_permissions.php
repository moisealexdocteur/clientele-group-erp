<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Version 0.4.0 : fichiers privés (reçus Sogebank, photos de véhicule,
 * documents de location), informations du contrat de location papier et
 * nouvelles permissions réservées à l'administration.
 */
return new class extends Migration
{
    /** Permissions ajoutées aux accès « Administrateur Car Rental » existants. */
    private const ADMINISTRATOR_PERMISSIONS = [
        'rental.reservations.override_rate',
        'rental.payments.credit',
        'rental.documents.sensitive',
    ];

    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('site_id')->nullable();
            $table->string('purpose', 48);
            $table->string('disk', 32)->default('local');
            $table->string('path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->uuid('uploaded_by')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'purpose']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table): void {
            // Identité du loueur imprimée sur le contrat de location.
            $table->string('legal_representative', 160)->nullable();
            $table->string('tax_identification_number', 64)->nullable();
            $table->text('legal_address')->nullable();
            $table->string('phone_numbers', 160)->nullable();
        });

        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->string('color', 48)->nullable();
            $table->string('fuel_type', 16)->nullable();
            $table->string('transmission', 16)->nullable();
            $table->unsignedInteger('engine_displacement_cc')->nullable();
            $table->unsignedTinyInteger('doors')->nullable();
            $table->uuid('photo_file_id')->nullable();
        });

        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            $table->boolean('rate_overridden')->default(false);
        });

        Schema::table('car_rental_payments', function (Blueprint $table): void {
            $table->uuid('proof_file_id')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE stored_files ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE stored_files FORCE ROW LEVEL SECURITY');
            DB::unprepared(
                'CREATE POLICY stored_files_company_isolation ON stored_files '
                . "USING (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid) "
                . "WITH CHECK (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid)"
            );
        }

        $this->grantAdministratorPermissions();
    }

    public function down(): void
    {
        Schema::table('car_rental_payments', function (Blueprint $table): void {
            $table->dropColumn('proof_file_id');
        });
        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            $table->dropColumn('rate_overridden');
        });
        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn(['color', 'fuel_type', 'transmission', 'engine_displacement_cc', 'doors', 'photo_file_id']);
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['legal_representative', 'tax_identification_number', 'legal_address', 'phone_numbers']);
        });
        Schema::dropIfExists('stored_files');
    }

    private function grantAdministratorPermissions(): void
    {
        DB::table('company_user_access')
            ->where('role_key', 'car_rental_administrator')
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

                $allowed = array_values(array_unique([...$allowed, ...self::ADMINISTRATOR_PERMISSIONS]));

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
