<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Photo générique d'un modèle de moto (photo du fabricant ou du magasin),
 * affichée aux vendeurs dans les listes. Fichier privé de la société.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moto_models', function (Blueprint $table): void {
            $table->uuid('photo_file_id')->nullable()->after('is_active');
            $table->foreign('photo_file_id')->references('id')->on('stored_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('moto_models', function (Blueprint $table): void {
            $table->dropForeign(['photo_file_id']);
            $table->dropColumn('photo_file_id');
        });
    }
};
