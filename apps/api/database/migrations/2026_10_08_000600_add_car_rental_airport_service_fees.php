<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            /*
             * Les frais de service aéroport restent en USD. Le tarif de location
             * peut être en HTG ou en USD : aucune conversion implicite n'est faite.
             */
            $table->decimal('airport_pickup_fee_usd', 14, 2)->default(0);
            $table->decimal('airport_dropoff_fee_usd', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('car_rental_reservations', function (Blueprint $table): void {
            $table->dropColumn([
                'airport_pickup_fee_usd',
                'airport_dropoff_fee_usd',
            ]);
        });
    }
};
