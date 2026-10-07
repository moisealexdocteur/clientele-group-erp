<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('legal_name');
            $table->string('display_name');
            $table->enum('base_currency', ['HTG', 'USD'])->default('HTG');
            $table->string('timezone')->default('America/Port-au-Prince');
            $table->string('timezone_display_name')->default('Cap-Haïtien, Haïti');
            $table->string('locale')->default('fr-HT');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 32);
            $table->string('name');
            $table->text('address');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('site_id');
            $table->string('code', 32);
            $table->string('name');
            $table->uuid('device_id')->nullable();
            $table->boolean('automatic_print_enabled')->default(false);
            $table->string('customer_printer_profile')->nullable();
            $table->string('admin_printer_profile')->nullable();
            $table->boolean('customer_display_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['id', 'company_id']);
            $table->foreign(['site_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('sites')
                ->restrictOnDelete();
        });

        Schema::create('company_user_access', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('role_key', 64);
            $table->jsonb('permissions')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 16);
            $table->string('event_type', 120);
            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['company_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_event_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Les événements d''audit sont immuables';
                END;
                $$;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_prevent_mutation
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW
                EXECUTE FUNCTION prevent_audit_event_mutation();
            SQL);

            foreach (['sites', 'cash_registers', 'audit_events'] as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::unprepared(
                    "CREATE POLICY {$table}_company_isolation ON {$table} "
                    . "USING (company_id IS NULL OR company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid) "
                    . "WITH CHECK (company_id IS NULL OR company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid)"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('company_user_access');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('companies');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_event_mutation()');
        }
    }
};
