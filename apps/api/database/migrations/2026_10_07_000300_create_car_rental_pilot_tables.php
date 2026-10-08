<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Une identité groupe ne contient volontairement aucune donnée
         * nominative. Les profils et coordonnées restent par société dans
         * customer_profiles et sont soumis au contexte de société.
         */
        Schema::create('customer_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampsTz();
        });

        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_identity_id')->nullable();
            $table->string('customer_type', 16)->default('individual');
            $table->text('display_name');
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->string('email_search_hash', 64)->nullable();
            $table->string('phone_search_hash', 64)->nullable();
            $table->boolean('group_contact_sharing_consent')->default(false);
            $table->timestampTz('group_contact_sharing_consented_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'email_search_hash']);
            $table->index(['company_id', 'phone_search_hash']);
            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('customer_identity_id')->references('id')->on('customer_identities')->nullOnDelete();
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('scope', 64);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'scope']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('car_rental_vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('site_id');
            $table->string('code', 32);
            $table->string('category', 24);
            $table->string('operational_status', 24)->default('available');
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->string('vin', 64)->nullable();
            $table->unsignedBigInteger('latest_odometer_km')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'site_id', 'category', 'operational_status']);
            $table->foreign(['site_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('sites')
                ->restrictOnDelete();
        });

        Schema::create('car_rental_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('site_id');
            $table->uuid('customer_profile_id');
            $table->uuid('vehicle_id')->nullable();
            $table->string('reservation_number', 8);
            $table->string('state', 32)->default('reserved');
            $table->timestampTz('pickup_at');
            $table->timestampTz('due_at');
            $table->timestampTz('checked_out_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->string('pickup_location_type', 32)->default('site');
            $table->text('pickup_location_detail')->nullable();
            $table->string('dropoff_location_type', 32)->default('site');
            $table->text('dropoff_location_detail')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('daily_rate', 14, 2);
            $table->string('kilometer_plan', 16)->default('limited');
            $table->unsignedInteger('included_km')->nullable();
            $table->decimal('additional_km_rate', 14, 2)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'reservation_number']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'vehicle_id', 'state', 'pickup_at', 'due_at']);
            $table->index(['company_id', 'site_id', 'state', 'pickup_at']);
            $table->foreign(['site_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('sites')
                ->restrictOnDelete();
            $table->foreign(['customer_profile_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('customer_profiles')
                ->restrictOnDelete();
            $table->foreign(['vehicle_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_vehicles')
                ->restrictOnDelete();
        });

        Schema::create('car_rental_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->uuid('cash_register_id')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->string('payment_kind', 24)->default('rental');
            $table->string('method', 24);
            $table->string('status', 24)->default('submitted');
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->string('bank_name', 64)->nullable();
            $table->text('bank_reference')->nullable();
            $table->string('proof_storage_key')->nullable();
            $table->string('proof_sha256', 64)->nullable();
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'reservation_id', 'status']);
            $table->unique(['id', 'company_id']);
            $table->foreign(['reservation_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_reservations')
                ->restrictOnDelete();
            $table->foreign(['cash_register_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('cash_registers')
                ->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('car_rental_security_deposits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->uuid('payment_id')->nullable();
            $table->string('method', 32);
            $table->string('status', 32)->default('required');
            $table->string('currency', 3)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'reservation_id', 'status']);
            $table->foreign(['reservation_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_reservations')
                ->restrictOnDelete();
            $table->foreign(['payment_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_payments')
                ->restrictOnDelete();
        });

        Schema::create('car_rental_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->uuid('vehicle_id');
            $table->uuid('inspector_user_id')->nullable();
            $table->string('stage', 24);
            $table->string('status', 24)->default('draft');
            $table->timestampTz('inspected_at')->nullable();
            $table->unsignedBigInteger('odometer_km')->nullable();
            $table->decimal('fuel_level_percent', 5, 2)->nullable();
            $table->json('damage_sketch')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('customer_signed_at')->nullable();
            $table->timestampTz('company_signed_at')->nullable();
            $table->string('customer_signature_sha256', 64)->nullable();
            $table->string('company_signature_sha256', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['reservation_id', 'stage']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'vehicle_id', 'stage']);
            $table->foreign(['reservation_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_reservations')
                ->restrictOnDelete();
            $table->foreign(['vehicle_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_vehicles')
                ->restrictOnDelete();
            $table->foreign('inspector_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('car_rental_inspection_photos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('inspection_id');
            $table->string('storage_key');
            $table->string('sha256', 64);
            $table->text('caption')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'inspection_id']);
            $table->foreign(['inspection_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('car_rental_inspections')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'customer_profiles',
                'document_sequences',
                'car_rental_vehicles',
                'car_rental_reservations',
                'car_rental_payments',
                'car_rental_security_deposits',
                'car_rental_inspections',
                'car_rental_inspection_photos',
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
        Schema::dropIfExists('car_rental_inspection_photos');
        Schema::dropIfExists('car_rental_inspections');
        Schema::dropIfExists('car_rental_security_deposits');
        Schema::dropIfExists('car_rental_payments');
        Schema::dropIfExists('car_rental_reservations');
        Schema::dropIfExists('car_rental_vehicles');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('customer_profiles');
        Schema::dropIfExists('customer_identities');
    }
};
