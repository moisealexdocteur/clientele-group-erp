<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Auto Parts, vente de motocyclettes Haojin (fournisseur Novamat Motors) :
 * modèles et prix, commandes payées d'avance par dépôt Sogebank (HTG ou USD),
 * liste d'attente dans l'ordre de paiement, lots reçus avec numéros de châssis
 * et de moteur, attribution, remise avec pièce d'identité, checklist,
 * accessoires et document de dédouanement, reçu de vente numéroté.
 */
return new class extends Migration
{
    private const TABLES = ['moto_models', 'moto_batches', 'moto_units', 'moto_orders'];

    /** @var array<string, array<int, string>> */
    private const ROLE_PERMISSIONS = [
        'auto_parts_administrator' => [
            'parts.motos.read',
            'parts.motos.orders.create',
            'parts.motos.payments.verify',
            'parts.motos.receive',
            'parts.motos.assign',
            'parts.motos.deliver',
            'parts.motos.models.manage',
        ],
        'auto_parts_seller' => ['parts.motos.read', 'parts.motos.orders.create', 'parts.motos.deliver'],
        'auto_parts_storekeeper' => ['parts.motos.read', 'parts.motos.receive'],
    ];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Réglage d'alignement de l'impression sur le certificat de vente du fournisseur.
            $table->decimal('moto_certificate_offset_x_mm', 5, 1)->default(0);
            $table->decimal('moto_certificate_offset_y_mm', 5, 1)->default(0);
        });

        Schema::create('moto_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('make', 40)->default('Haojin');
            $table->string('model', 60);
            $table->unsignedSmallInteger('displacement_cc')->nullable();
            $table->unsignedTinyInteger('cylinders')->default(1);
            $table->decimal('price', 14, 2);
            $table->string('price_currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'make', 'model']);
            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('moto_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('site_id');
            $table->string('reference', 120);
            $table->text('note')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'received_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('moto_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // Adresse où le client récupère sa moto.
            $table->uuid('site_id');
            $table->char('number', 8);
            // submitted : dépôt à vérifier ; paid : en attente de moto ; assigned : moto attribuée ;
            // delivered : remise faite ; cancelled : annulée.
            $table->string('status', 16)->default('submitted');
            $table->uuid('model_id');
            $table->string('preferred_color', 60)->nullable();
            $table->string('customer_name', 160);
            $table->string('customer_phone', 40);
            $table->string('customer_email', 254)->nullable();
            $table->string('customer_address', 255)->nullable();
            $table->decimal('price', 14, 2);
            $table->string('price_currency', 3);
            $table->decimal('payment_amount', 14, 2);
            $table->string('payment_currency', 3);
            $table->decimal('exchange_rate_htg_per_usd', 12, 4)->nullable();
            $table->decimal('amount_in_price_currency', 14, 2);
            $table->string('bank_name', 60)->default('Sogebank');
            $table->string('deposit_reference', 80);
            $table->date('deposit_date');
            $table->uuid('deposit_proof_file_id')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('submitted_at');
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('unit_id')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->text('assignment_note')->nullable();
            $table->uuid('called_by')->nullable();
            $table->timestampTz('called_at')->nullable();
            $table->string('id_document_type', 32)->nullable();
            $table->string('id_document_number', 64)->nullable();
            $table->jsonb('checklist')->nullable();
            $table->jsonb('accessories')->nullable();
            $table->boolean('customs_document_delivered')->nullable();
            $table->uuid('customs_document_delivered_by')->nullable();
            $table->timestampTz('customs_document_delivered_at')->nullable();
            $table->char('receipt_number', 8)->nullable();
            $table->unsignedSmallInteger('receipt_print_count')->default(0);
            $table->unsignedSmallInteger('certificate_print_count')->default(0);
            $table->uuid('delivered_by')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'receipt_number']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'status', 'deposit_date']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
            $table->foreign(['model_id', 'company_id'])->references(['id', 'company_id'])->on('moto_models')->restrictOnDelete();
            $table->foreign('deposit_proof_file_id')->references('id')->on('stored_files')->nullOnDelete();
            foreach (['submitted_by', 'verified_by', 'assigned_by', 'called_by', 'customs_document_delivered_by', 'delivered_by', 'cancelled_by'] as $column) {
                $table->foreign($column)->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::create('moto_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('batch_id');
            $table->uuid('site_id');
            $table->uuid('model_id');
            $table->unsignedSmallInteger('model_year');
            // Frame No et Engine No du certificat du fournisseur.
            $table->string('frame_number', 64);
            $table->string('engine_number', 64);
            $table->string('color', 60);
            $table->unsignedTinyInteger('cylinders')->default(1);
            $table->unsignedSmallInteger('displacement_cc')->nullable();
            $table->boolean('customs_document_available')->default(true);
            // in_stock : disponible ; reserved : attribuée à une commande ; delivered : remise.
            $table->string('status', 16)->default('in_stock');
            $table->uuid('order_id')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'frame_number']);
            $table->unique(['company_id', 'engine_number']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'status', 'model_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['batch_id', 'company_id'])->references(['id', 'company_id'])->on('moto_batches')->restrictOnDelete();
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
            $table->foreign(['model_id', 'company_id'])->references(['id', 'company_id'])->on('moto_models')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $name) {
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
        Schema::dropIfExists('moto_units');
        Schema::dropIfExists('moto_orders');
        Schema::dropIfExists('moto_batches');
        Schema::dropIfExists('moto_models');
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['moto_certificate_offset_x_mm', 'moto_certificate_offset_y_mm']);
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
