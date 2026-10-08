<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Version 0.5.0 : permis international (pays et subdivision émettrice,
 * photos recto et verso), fiche de sortie, signatures tactiles et contrat
 * de location signé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Conditions générales du contrat de location, saisies par le propriétaire.
            $table->text('rental_contract_terms')->nullable();
        });

        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            $table->char('driver_license_country', 2)->nullable();
            $table->string('driver_license_subdivision', 64)->nullable();
            $table->uuid('driver_license_front_file_id')->nullable();
            $table->uuid('driver_license_back_file_id')->nullable();
            $table->text('additional_driver_name')->nullable();
            $table->text('additional_driver_license_number')->nullable();
            // Copie figée des éléments du contrat au moment de la signature.
            $table->json('contract_snapshot')->nullable();
            $table->uuid('contract_file_id')->nullable();
            $table->timestampTz('contract_issued_at')->nullable();
        });

        Schema::table('car_rental_inspections', function (Blueprint $table): void {
            $table->json('accessories')->nullable();
            $table->json('photo_file_ids')->nullable();
            $table->string('company_signer_name', 160)->nullable();
            $table->uuid('customer_signature_file_id')->nullable();
            $table->uuid('company_signature_file_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('car_rental_inspections', function (Blueprint $table): void {
            $table->dropColumn([
                'accessories',
                'photo_file_ids',
                'company_signer_name',
                'customer_signature_file_id',
                'company_signature_file_id',
            ]);
        });
        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            $table->dropColumn([
                'driver_license_country',
                'driver_license_subdivision',
                'driver_license_front_file_id',
                'driver_license_back_file_id',
                'additional_driver_name',
                'additional_driver_license_number',
                'contract_snapshot',
                'contract_file_id',
                'contract_issued_at',
            ]);
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('rental_contract_terms');
        });
    }
};
