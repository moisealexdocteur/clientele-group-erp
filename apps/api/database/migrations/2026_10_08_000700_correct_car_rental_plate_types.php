<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $normaliseForCompany = static function (string $companyId): void {
            DB::table('car_rental_vehicles')
                ->where('company_id', $companyId)
                ->where('registration_status', 'official')
                ->update(['registration_status' => 'normal']);

            DB::table('car_rental_vehicle_registration_events')
                ->where('company_id', $companyId)
                ->where('previous_registration_status', 'official')
                ->update(['previous_registration_status' => 'normal']);

            DB::table('car_rental_vehicle_registration_events')
                ->where('company_id', $companyId)
                ->where('current_registration_status', 'official')
                ->update(['current_registration_status' => 'normal']);
        };

        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
            if (DB::getDriverName() !== 'pgsql') {
                $normaliseForCompany($companyId);
                continue;
            }

            DB::transaction(function () use ($companyId, $normaliseForCompany): void {
                DB::select("select set_config('app.current_company_id', ?, true)", [$companyId]);
                $normaliseForCompany($companyId);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE car_rental_vehicles ALTER COLUMN registration_status SET DEFAULT 'normal'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE car_rental_vehicles ALTER COLUMN registration_status SET DEFAULT 'official'");
        }
    }
};
