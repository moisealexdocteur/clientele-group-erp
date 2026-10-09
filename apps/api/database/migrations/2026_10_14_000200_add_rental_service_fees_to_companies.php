<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Frais de service Car Rental réglés dans Configuration, par société : prise
 * en charge ou retour à l'aéroport, nettoyage. Les valeurs confirmées par la
 * direction (20 USD chacun) sont reprises comme valeurs de départ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->decimal('rental_airport_fee_usd', 10, 2)->default('20.00');
            $table->decimal('rental_cleaning_fee_usd', 10, 2)->default('20.00');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['rental_airport_fee_usd', 'rental_cleaning_fee_usd']);
        });
    }
};
