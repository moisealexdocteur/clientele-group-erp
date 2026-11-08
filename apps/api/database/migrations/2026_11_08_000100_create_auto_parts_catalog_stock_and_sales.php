<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Auto Parts et motocyclettes, premier lot : catalogue de pièces avec
 * compatibilités, stock par emplacement (magasin ou entrepôt), journal des
 * mouvements immuable, vente au comptoir en espèces HTG avec reçu numéroté.
 *
 * Une société porte son activité (car_rental ou auto_parts) et chaque adresse
 * son type (bureau, magasin ou entrepôt). Les sociétés existantes restent
 * Car Rental et leurs adresses restent des bureaux.
 */
return new class extends Migration
{
    private const TABLES = [
        'parts_products',
        'parts_product_fitments',
        'parts_stock_levels',
        'parts_stock_movements',
        'parts_sales',
        'parts_sale_lines',
    ];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('activity', 32)->default('car_rental');
        });

        Schema::table('sites', function (Blueprint $table): void {
            // office : bureau ; store : magasin qui vend ; warehouse : entrepôt.
            $table->string('kind', 16)->default('office');
        });

        Schema::create('parts_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // Référence de l'article, unique dans la société.
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->string('name', 255);
            $table->string('category', 80)->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('unit', 24)->default('pièce');
            $table->decimal('retail_price_htg', 14, 2);
            $table->decimal('wholesale_price_htg', 14, 2)->nullable();
            $table->unsignedInteger('reorder_level')->default(0);
            $table->uuid('photo_file_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'is_active', 'name']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('photo_file_id')->references('id')->on('stored_files')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // Compatibilité : marque, modèle et années de motocyclette lorsque connus.
        Schema::create('parts_product_fitments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('product_id');
            $table->string('make', 60);
            $table->string('model', 80)->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'make', 'model']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['product_id', 'company_id'])->references(['id', 'company_id'])->on('parts_products')->cascadeOnDelete();
        });

        Schema::create('parts_stock_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('product_id');
            $table->uuid('site_id');
            $table->integer('quantity')->default(0);
            $table->timestampsTz();

            $table->unique(['product_id', 'site_id']);
            $table->index(['company_id', 'site_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['product_id', 'company_id'])->references(['id', 'company_id'])->on('parts_products')->restrictOnDelete();
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
        });

        Schema::create('parts_sales', function (Blueprint $table): void {
            // Identifiant fourni par le poste : un double envoi ne crée jamais deux ventes.
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('site_id');
            $table->uuid('cash_register_id');
            $table->uuid('cash_session_id');
            // Numéro de reçu sur huit chiffres, partagé avec les autres reçus de la société.
            $table->char('number', 8);
            // retail : prix détail ; wholesale : prix de gros.
            $table->string('price_list', 16)->default('retail');
            $table->string('customer_name', 120)->nullable();
            $table->string('status', 16)->default('completed');
            $table->string('currency', 3)->default('HTG');
            $table->unsignedInteger('item_count');
            $table->decimal('total', 14, 2);
            $table->decimal('amount_received', 14, 2);
            $table->decimal('change_given', 14, 2);
            $table->uuid('sold_by')->nullable();
            $table->timestampTz('sold_at');
            $table->unsignedSmallInteger('receipt_print_count')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'site_id', 'sold_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
            $table->foreign(['cash_register_id', 'company_id'])->references(['id', 'company_id'])->on('cash_registers')->restrictOnDelete();
            $table->foreign(['cash_session_id', 'company_id'])->references(['id', 'company_id'])->on('cash_sessions')->restrictOnDelete();
            $table->foreign('sold_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('parts_sale_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('sale_id');
            $table->uuid('product_id');
            // Emplacement d'où sort la pièce : le magasin vendeur ou un entrepôt.
            $table->uuid('stock_site_id');
            $table->string('sku', 64);
            $table->string('name', 255);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestampsTz();

            $table->index(['company_id', 'sale_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['sale_id', 'company_id'])->references(['id', 'company_id'])->on('parts_sales')->restrictOnDelete();
            $table->foreign(['product_id', 'company_id'])->references(['id', 'company_id'])->on('parts_products')->restrictOnDelete();
            $table->foreign(['stock_site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
        });

        // Journal des mouvements de stock : une ligne par entrée, sortie ou correction.
        Schema::create('parts_stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('product_id');
            $table->uuid('site_id');
            // receipt, adjustment, transfer_out, transfer_in, sale
            $table->string('kind', 24);
            // Quantité signée : positive en entrée, négative en sortie.
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->decimal('unit_cost_htg', 14, 2)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->uuid('transfer_id')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['company_id', 'product_id', 'occurred_at']);
            $table->index(['company_id', 'site_id', 'occurred_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['product_id', 'company_id'])->references(['id', 'company_id'])->on('parts_products')->restrictOnDelete();
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->uuid('sale_id')->nullable();
            $table->unique(['kind', 'sale_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE parts_stock_levels ADD CONSTRAINT parts_stock_levels_quantity_not_negative CHECK (quantity >= 0)');

            foreach (self::TABLES as $name) {
                DB::unprepared("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
                DB::unprepared(
                    "CREATE POLICY {$name}_company_isolation ON {$name} "
                    . "USING (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid) "
                    . "WITH CHECK (company_id = NULLIF(current_setting('app.current_company_id', true), '')::uuid)"
                );
            }

            // Le journal de stock ne se modifie pas : une erreur se corrige par un ajustement.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_parts_stock_movement_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Les mouvements de stock sont immuables';
                END;
                $$;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER parts_stock_movements_prevent_mutation
                BEFORE UPDATE OR DELETE ON parts_stock_movements
                FOR EACH ROW
                EXECUTE FUNCTION prevent_parts_stock_movement_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropUnique(['kind', 'sale_id']);
            $table->dropColumn('sale_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS parts_stock_movements_prevent_mutation ON parts_stock_movements');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_parts_stock_movement_mutation()');
        }

        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('activity');
        });
    }
};
