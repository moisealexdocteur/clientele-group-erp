<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Version 0.9.0 : cycle de caisse. Ouverture avec fond de caisse USD et HTG,
 * journal des entrées et sorties d'espèces, clôture avec montants déclarés,
 * écarts expliqués et approuvés, rapport journalier exportable.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const ROLE_PERMISSIONS = [
        'car_rental_administrator' => ['cash.sessions.operate', 'cash.sessions.approve', 'cash.reports.read'],
        'car_rental_agent' => ['cash.sessions.operate'],
    ];

    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('site_id');
            $table->uuid('cash_register_id');
            $table->string('status', 16)->default('open');
            $table->uuid('opened_by')->nullable();
            $table->timestampTz('opened_at');
            $table->decimal('opening_usd', 14, 2)->default(0);
            $table->decimal('opening_htg', 14, 2)->default(0);
            $table->uuid('closed_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->decimal('expected_usd', 14, 2)->nullable();
            $table->decimal('expected_htg', 14, 2)->nullable();
            $table->decimal('declared_usd', 14, 2)->nullable();
            $table->decimal('declared_htg', 14, 2)->nullable();
            $table->decimal('variance_usd', 14, 2)->nullable();
            $table->decimal('variance_htg', 14, 2)->nullable();
            $table->text('variance_note')->nullable();
            // none : aucun écart ; pending : écart à approuver ; approved : écart approuvé.
            $table->string('review_status', 16)->default('none');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedSmallInteger('report_print_count')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'opened_at']);
            $table->index(['company_id', 'cash_register_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
            $table->foreign(['cash_register_id', 'company_id'])->references(['id', 'company_id'])->on('cash_registers')->restrictOnDelete();
            $table->foreign('opened_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        // Une seule session ouverte par caisse.
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_register ON cash_sessions (cash_register_id) WHERE status = 'open'");

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('cash_session_id');
            // rental_payment, deposit_payment, deposit_refund
            $table->string('kind', 32);
            // in : entrée d'espèces ; out : sortie d'espèces.
            $table->string('direction', 3);
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->uuid('payment_id')->nullable();
            $table->uuid('deposit_id')->nullable();
            $table->uuid('reservation_id')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['company_id', 'cash_session_id', 'occurred_at']);
            $table->unique(['kind', 'payment_id']);
            $table->unique(['kind', 'deposit_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['cash_session_id', 'company_id'])->references(['id', 'company_id'])->on('cash_sessions')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('car_rental_payments', function (Blueprint $table): void {
            $table->uuid('cash_session_id')->nullable();
            $table->index(['company_id', 'cash_session_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['cash_sessions', 'cash_movements'] as $name) {
                DB::unprepared("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
                DB::unprepared(
                    "CREATE POLICY {$name}_company_isolation ON {$name} "
                    . "USING (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid) "
                    . "WITH CHECK (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid)"
                );
            }
        }

        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::table('car_rental_payments', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'cash_session_id']);
            $table->dropColumn('cash_session_id');
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
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
