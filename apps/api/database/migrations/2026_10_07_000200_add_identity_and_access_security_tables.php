<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('password');
            $table->string('system_role', 32)->default('user')->after('is_active');
            $table->timestampTz('two_factor_email_verified_at')->nullable()->after('two_factor_email_enabled');
            $table->timestampTz('last_login_at')->nullable()->after('remember_token');
            $table->index(['is_active', 'system_role']);
        });

        /*
         * Aucun compte humain du produit ne contourne la 2FA par courriel.
         * Le champ existe dans la fondation initiale : cette mise à niveau rend
         * aussi conformes les éventuels comptes créés avant cette migration.
         */
        DB::table('users')->update(['two_factor_email_enabled' => true]);

        Schema::table('company_user_access', function (Blueprint $table): void {
            $table->string('site_scope', 16)->default('all')->after('role_key');
            $table->unique(['id', 'company_id']);
        });

        Schema::create('company_user_site_access', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_user_access_id');
            $table->uuid('company_id');
            $table->uuid('site_id');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_user_access_id', 'site_id']);
            $table->index(['company_id', 'site_id', 'is_active']);
            $table->foreign(['company_user_access_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('company_user_access')
                ->cascadeOnDelete();
            $table->foreign(['site_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('sites')
                ->restrictOnDelete();
        });

        Schema::create('api_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 20);
            $table->string('device_name', 120)->nullable();
            $table->jsonb('abilities')->default('[]');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at', 'expires_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('email_otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('purpose', 32);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->string('requested_ip_address', 45)->nullable();
            $table->string('requested_user_agent', 512)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'purpose', 'expires_at']);
            $table->index(['expires_at', 'consumed_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otp_challenges');
        Schema::dropIfExists('api_access_tokens');
        Schema::dropIfExists('company_user_site_access');

        Schema::table('company_user_access', function (Blueprint $table): void {
            $table->dropUnique(['id', 'company_id']);
            $table->dropColumn('site_scope');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'system_role']);
            $table->dropColumn([
                'is_active',
                'system_role',
                'two_factor_email_verified_at',
                'last_login_at',
            ]);
        });
    }
};
