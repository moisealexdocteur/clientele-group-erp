<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            /*
             * Clé d'une image de référence publiée par Clientèle Group.
             * Ce n'est ni une photo d'inspection, ni un justificatif de propriété.
             */
            $table->string('reference_photo_key', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('car_rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn('reference_photo_key');
        });
    }
};
