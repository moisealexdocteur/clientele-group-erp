<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->decimal('daily_rate_usd', 14, 2)->nullable();
            $table->decimal('minimum_security_deposit_usd', 14, 2)->nullable();
        });

        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            /*
             * Le montant est copié depuis le véhicule au moment de la
             * réservation. Une modification ultérieure de la fiche véhicule
             * ne modifie donc jamais une réservation déjà confirmée.
             */
            $table->decimal('minimum_security_deposit_usd', 14, 2)->nullable();
            $table->string('driver_full_name', 160)->nullable();
            $table->text('driver_license_number')->nullable();
            $table->date('driver_license_expires_at')->nullable();
            $table->timestampTz('driver_license_verified_at')->nullable();
        });

        Schema::table('car_rental_security_deposits', function (Blueprint $table): void {
            $table->unique('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('car_rental_security_deposits', function (Blueprint $table): void {
            $table->dropUnique(['payment_id']);
        });

        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            $table->dropColumn([
                'minimum_security_deposit_usd',
                'driver_full_name',
                'driver_license_number',
                'driver_license_expires_at',
                'driver_license_verified_at',
            ]);
        });

        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn([
                'daily_rate_usd',
                'minimum_security_deposit_usd',
            ]);
        });
    }
};
