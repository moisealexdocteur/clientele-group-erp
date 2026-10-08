<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('company_user_access')
            ->whereIn('role_key', ['car_rental_administrator', 'car_rental_agent'])
            ->orderBy('id')
            ->get()
            ->each(static function (object $access): void {
                $permissions = is_string($access->permissions)
                    ? json_decode($access->permissions, true)
                    : $access->permissions;
                $permissions = is_array($permissions) ? $permissions : [];
                $allowed = array_is_list($permissions)
                    ? $permissions
                    : ($permissions['allow'] ?? []);

                if (in_array('*', $allowed, true) || in_array('rental.reservations.manage', $allowed, true)) {
                    return;
                }

                $allowed[] = 'rental.reservations.manage';

                if (array_is_list($permissions)) {
                    $permissions = array_values($allowed);
                } else {
                    $permissions['allow'] = array_values($allowed);
                }

                DB::table('company_user_access')
                    ->where('id', $access->id)
                    ->update(['permissions' => json_encode($permissions, JSON_THROW_ON_ERROR)]);
            });
    }

    public function down(): void
    {
        // Une permission éventuellement accordée manuellement n'est pas retirée
        // automatiquement lors d'un retour de version.
    }
};
