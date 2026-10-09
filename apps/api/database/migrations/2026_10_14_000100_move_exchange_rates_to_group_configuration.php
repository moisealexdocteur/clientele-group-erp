<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Version 0.8.0 : le taux HTG/USD devient un réglage unique du groupe, saisi
 * dans Configuration par le propriétaire ou par les personnes qu'il désigne.
 * Les taux déjà saisis par société sont repris dans l'historique du groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_manage_exchange_rates')->default(false);
        });

        Schema::create('group_exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Nombre de gourdes pour un dollar américain.
            $table->decimal('rate_htg_per_usd', 12, 4);
            $table->decimal('brh_reference_rate', 12, 4)->nullable();
            $table->date('brh_reference_date')->nullable();
            $table->boolean('below_brh')->default(false);
            $table->text('note')->nullable();
            $table->uuid('set_by')->nullable();
            $table->timestampTz('effective_at');
            $table->timestampsTz();

            $table->index('effective_at');
            $table->foreign('set_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->copyCompanyRates();
        Schema::dropIfExists('exchange_rates');
        $this->removeCompanyRatePermission();
    }

    public function down(): void
    {
        Schema::dropIfExists('group_exchange_rates');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_manage_exchange_rates');
        });
    }

    /** Les lignes par société sont protégées par RLS : chaque société est lue dans son contexte. */
    private function copyCompanyRates(): void
    {
        if (! Schema::hasTable('exchange_rates')) {
            return;
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.current_company_id', ?, true)", [$companyId]);
            }

            DB::table('exchange_rates')
                ->where('company_id', $companyId)
                ->orderBy('effective_at')
                ->get()
                ->each(static function (object $rate): void {
                    DB::table('group_exchange_rates')->insert([
                        'id' => (string) Str::uuid(),
                        'rate_htg_per_usd' => $rate->rate_htg_per_usd,
                        'brh_reference_rate' => $rate->brh_reference_rate,
                        'brh_reference_date' => $rate->brh_reference_date,
                        'below_brh' => $rate->below_brh,
                        'note' => $rate->note,
                        'set_by' => $rate->set_by,
                        'effective_at' => $rate->effective_at,
                        'created_at' => $rate->created_at,
                        'updated_at' => $rate->updated_at,
                    ]);
                });
        }
    }

    /** La permission par société `finance.rates.manage` est remplacée par le droit utilisateur du groupe. */
    private function removeCompanyRatePermission(): void
    {
        DB::table('company_user_access')
            ->orderBy('id')
            ->get()
            ->each(static function (object $access): void {
                $permissions = is_string($access->permissions)
                    ? json_decode($access->permissions, true)
                    : $access->permissions;

                if (! is_array($permissions)) {
                    return;
                }

                $allowed = array_is_list($permissions) ? $permissions : ($permissions['allow'] ?? []);

                if (! in_array('finance.rates.manage', $allowed, true)) {
                    return;
                }

                $allowed = array_values(array_filter($allowed, static fn ($permission): bool => $permission !== 'finance.rates.manage'));

                if (array_is_list($permissions)) {
                    $permissions = $allowed;
                } else {
                    $permissions['allow'] = $allowed;
                }

                DB::table('company_user_access')
                    ->where('id', $access->id)
                    ->update(['permissions' => json_encode($permissions, JSON_THROW_ON_ERROR)]);
            });
    }
};
