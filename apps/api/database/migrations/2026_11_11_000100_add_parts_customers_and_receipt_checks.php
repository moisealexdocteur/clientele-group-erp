<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Base clientèle Auto Parts : les commandes de motos et les ventes au comptoir
 * sont rattachées à une fiche client de la société (customer_profiles, déjà
 * isolée par société et chiffrée). Adresse ajoutée à la fiche.
 * Permissions clients ajoutées aux profils Auto Parts existants.
 */
return new class extends Migration
{
    private const ROLE_PERMISSIONS = [
        'auto_parts_administrator' => ['parts.customers.read', 'parts.customers.manage'],
        'auto_parts_seller' => ['parts.customers.read', 'parts.customers.manage'],
    ];

    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->text('address')->nullable()->after('phone');
        });

        Schema::table('moto_orders', function (Blueprint $table): void {
            $table->uuid('customer_profile_id')->nullable()->after('site_id');
            $table->foreign('customer_profile_id')->references('id')->on('customer_profiles')->nullOnDelete();
            $table->index(['company_id', 'customer_profile_id']);
        });

        Schema::table('parts_sales', function (Blueprint $table): void {
            $table->uuid('customer_profile_id')->nullable()->after('site_id');
            $table->foreign('customer_profile_id')->references('id')->on('customer_profiles')->nullOnDelete();
            $table->index(['company_id', 'customer_profile_id']);
        });

        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::table('parts_sales', function (Blueprint $table): void {
            $table->dropForeign(['customer_profile_id']);
            $table->dropIndex(['company_id', 'customer_profile_id']);
            $table->dropColumn('customer_profile_id');
        });
        Schema::table('moto_orders', function (Blueprint $table): void {
            $table->dropForeign(['customer_profile_id']);
            $table->dropIndex(['company_id', 'customer_profile_id']);
            $table->dropColumn('customer_profile_id');
        });
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropColumn('address');
        });
    }

    private function grantPermissions(): void
    {
        DB::table('company_user_access')
            ->whereIn('role_key', array_keys(self::ROLE_PERMISSIONS))
            ->orderBy('id')
            ->get()
            ->each(static function (object $access): void {
                $permissions = is_string($access->permissions)
                    ? json_decode($access->permissions, true)
                    : $access->permissions;
                $permissions = is_array($permissions) ? $permissions : [];
                $allowed = array_is_list($permissions) ? $permissions : ($permissions['allow'] ?? []);

                if (in_array('*', $allowed, true)) {
                    return;
                }

                $allowed = array_values(array_unique([...$allowed, ...self::ROLE_PERMISSIONS[$access->role_key]]));
                $permissions = array_is_list($permissions) ? $allowed : [...$permissions, 'allow' => $allowed];

                DB::table('company_user_access')
                    ->where('id', $access->id)
                    ->update(['permissions' => json_encode($permissions)]);
            });
    }
};
