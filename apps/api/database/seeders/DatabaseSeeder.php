<?php

namespace Database\Seeders;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Aucun compte, société, adresse ou caisse de production n'est créé
        // automatiquement. Ils doivent être configurés et audités par le
        // propriétaire après la recette de préproduction.
    }
}
