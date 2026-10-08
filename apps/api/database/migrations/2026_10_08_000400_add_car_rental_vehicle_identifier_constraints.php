<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->unique(['company_id', 'registration_number'], 'rental_vehicle_company_registration_unique');
            $table->unique(['company_id', 'vin'], 'rental_vehicle_company_vin_unique');
        });
    }

    public function down(): void
    {
        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->dropUnique('rental_vehicle_company_registration_unique');
            $table->dropUnique('rental_vehicle_company_vin_unique');
        });
    }
};
