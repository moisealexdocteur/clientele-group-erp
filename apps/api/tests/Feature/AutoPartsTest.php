<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\PartsSale;
use App\Models\Site;
use App\Models\User;
use App\Support\RoleProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AutoPartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_stock_transfer_and_counter_sale_cycle(): void
    {
        [$company, $store, $warehouse] = $this->company('AUTO');
        $token = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $register = $this->register($company, $store, 'PARTS-01');

        // Article créé avec un stock initial à l'entrepôt.
        $product = $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/products', [
                'sku' => ' hj-125 chain ',
                'name' => 'Chaîne de transmission 428H',
                'category' => 'Transmission',
                'brand' => 'KMC',
                'unit' => 'pièce',
                'retail_price_htg' => '1500',
                'wholesale_price_htg' => '1250.50',
                'reorder_level' => 3,
                'initial_site_id' => $warehouse->id,
                'initial_quantity' => 10,
                'initial_unit_cost_htg' => '900.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'HJ-125 CHAIN')
            ->assertJsonPath('data.retail_price_htg', '1500.00')
            ->assertJsonPath('data.wholesale_price_htg', '1250.50')
            ->assertJsonPath('data.total_stock', 10)
            ->json('data');

        $this->requestFor($token, $company)
            ->putJson("/api/v1/parts/products/{$product['id']}/fitments", [
                'fitments' => [['make' => 'Haojin', 'model' => 'HJ125-7', 'year_from' => 2018, 'year_to' => 2023]],
            ])
            ->assertOk()
            ->assertJsonPath('data.fitments.0.label', 'Haojin HJ125-7 2018-2023');

        // Recherche par compatibilité.
        $this->requestFor($token, $company)
            ->getJson('/api/v1/parts/products?q=hj125')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $product['id']);

        // Réception au magasin, puis transfert de l'entrepôt vers le magasin.
        $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/stock/receipts', [
                'site_id' => $store->id,
                'reference' => 'BL 2026-114',
                'lines' => [['product_id' => $product['id'], 'quantity' => 2, 'unit_cost_htg' => '950']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.0.balance_after', 2);

        $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/stock/transfers', [
                'product_id' => $product['id'],
                'from_site_id' => $warehouse->id,
                'to_site_id' => $store->id,
                'quantity' => 4,
            ])
            ->assertCreated()
            ->assertJsonPath('data.0.kind', 'transfer_out')
            ->assertJsonPath('data.0.balance_after', 6)
            ->assertJsonPath('data.1.kind', 'transfer_in')
            ->assertJsonPath('data.1.balance_after', 6);

        $sale = [
            'id' => (string) Str::uuid(),
            'site_id' => $store->id,
            'cash_register_id' => $register->id,
            'price_list' => 'retail',
            'amount_received' => '5000',
            'lines' => [
                ['product_id' => $product['id'], 'stock_site_id' => $store->id, 'quantity' => 2],
                ['product_id' => $product['id'], 'stock_site_id' => $warehouse->id, 'quantity' => 1],
            ],
        ];

        // Caisse fermée : la vente est refusée et rien ne bouge.
        $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/sales', $sale)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_register_id');
        self::assertSame(0, PartsSale::query()->count());

        $session = $this->requestFor($token, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '0', 'opening_htg' => '2000'])
            ->assertCreated()
            ->json('data');

        $created = $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/sales', $sale)
            ->assertCreated()
            ->assertJsonPath('data.number', '0000 0001')
            ->assertJsonPath('data.total', '4500.00')
            ->assertJsonPath('data.change_given', '500.00')
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonPath('data.lines.0.unit_price', '1500.00')
            ->json('data');

        // Le même envoi ne crée pas une seconde vente.
        $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/sales', $sale)
            ->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.number', '0000 0001');
        self::assertSame(1, PartsSale::query()->count());

        $detail = $this->requestFor($token, $company)
            ->getJson("/api/v1/parts/products/{$product['id']}")
            ->assertOk()
            ->json('data');
        $stock = collect($detail['stock'])->keyBy('site_id');
        self::assertSame(4, $stock[$store->id]['quantity']);
        self::assertSame(5, $stock[$warehouse->id]['quantity']);
        self::assertContains('sale', array_column($detail['movements'], 'kind'));

        // L'encaissement entre dans la session : 2 000 + 4 500 HTG attendus.
        $this->requestFor($token, $company)
            ->getJson("/api/v1/cash/sessions/{$session['id']}")
            ->assertOk()
            ->assertJsonPath('data.totals.HTG.in', '4500.00')
            ->assertJsonPath('data.totals.HTG.expected', '6500.00')
            ->assertJsonPath('data.movements.0.label', 'Vente de pièces')
            ->assertJsonPath('data.movements.0.receipt_number', '0000 0001');
        self::assertSame(1, CashMovement::query()->where('kind', 'parts_sale')->count());

        // Rapport journalier et liste des ventes du jour, sans nom de client.
        $this->requestFor($token, $company)
            ->getJson('/api/v1/cash/reports/daily')
            ->assertOk()
            ->assertJsonPath('data.activity', 'auto_parts')
            ->assertJsonPath('data.parts_sales.0.label', 'Détail')
            ->assertJsonPath('data.parts_sales.0.amount', '4500.00');
        $list = $this->requestFor($token, $company)
            ->getJson('/api/v1/parts/sales')
            ->assertOk()
            ->assertJsonPath('meta.total', '4500.00')
            ->json('data.0');
        self::assertArrayNotHasKey('customer_name', $list);

        // Impression puis réimpression journalisées.
        foreach (['receipt.printed', 'receipt.reprinted'] as $event) {
            $this->requestFor($token, $company)
                ->postJson("/api/v1/parts/sales/{$created['id']}/receipt/prints", ['copy' => 'client'])
                ->assertOk();
            self::assertTrue(AuditEvent::query()->where('event_type', $event)->where('subject_id', $created['id'])->exists());
        }

        // Vérification publique du QR.
        parse_str((string) parse_url((string) $created['verification_url'], PHP_URL_QUERY), $query);
        $this->getJson('/api/v1/public/receipts/AUTO/00000001?s=' . $query['s'])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('amount', '4500.00')
            ->assertJsonPath('currency', 'HTG');
        PartsSale::query()->whereKey($created['id'])->update(['total' => '4600.00']);
        $this->getJson('/api/v1/public/receipts/AUTO/00000001?s=' . $query['s'])
            ->assertOk()
            ->assertJsonPath('valid', false);
    }

    public function test_a_sale_uses_catalog_prices_and_refuses_what_is_not_allowed(): void
    {
        [$company, $store, $warehouse] = $this->company('AUTO');
        $admin = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $seller = $this->user($company, RoleProfiles::permissions('auto_parts_seller'));
        $register = $this->register($company, $store, 'PARTS-01');
        $office = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'AUTO-BUR',
            'name' => 'Bureau administratif',
            'address' => 'Cap-Haïtien',
            'kind' => 'office',
        ]);

        $product = $this->product($admin, $company, 'FILTRE-01', '350', '300', $store, 2);
        $this->requestFor($seller, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '0', 'opening_htg' => '0'])
            ->assertCreated();

        $base = [
            'site_id' => $store->id,
            'cash_register_id' => $register->id,
            'price_list' => 'retail',
            'amount_received' => '10000',
        ];

        // Le vendeur ne voit pas le prix de gros et ne peut pas l'appliquer.
        $this->requestFor($seller, $company)
            ->getJson("/api/v1/parts/products/{$product}")
            ->assertOk()
            ->assertJsonPath('data.wholesale_price_htg', null)
            ->assertJsonPath('data.has_wholesale_price', true);
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/sales', [...$base, 'id' => (string) Str::uuid(), 'price_list' => 'wholesale', 'lines' => [
                ['product_id' => $product, 'stock_site_id' => $store->id, 'quantity' => 1],
            ]])
            ->assertForbidden();

        // Un prix envoyé par le poste est ignoré : le catalogue fait foi.
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/sales', [...$base, 'id' => (string) Str::uuid(), 'lines' => [
                ['product_id' => $product, 'stock_site_id' => $store->id, 'quantity' => 1, 'unit_price' => '1.00'],
            ]])
            ->assertCreated()
            ->assertJsonPath('data.total', '350.00');

        // Stock insuffisant : il reste une pièce au magasin, aucune à l'entrepôt.
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/sales', [...$base, 'id' => (string) Str::uuid(), 'lines' => [
                ['product_id' => $product, 'stock_site_id' => $store->id, 'quantity' => 2],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.quantity');
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/sales', [...$base, 'id' => (string) Str::uuid(), 'lines' => [
                ['product_id' => $product, 'stock_site_id' => $warehouse->id, 'quantity' => 1],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.quantity');

        // Montant reçu insuffisant, bureau comme emplacement de stock, article retiré.
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/sales', [...$base, 'id' => (string) Str::uuid(), 'amount_received' => '100', 'lines' => [
                ['product_id' => $product, 'stock_site_id' => $store->id, 'quantity' => 1],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_received');
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/sales', [...$base, 'id' => (string) Str::uuid(), 'lines' => [
                ['product_id' => $product, 'stock_site_id' => $office->id, 'quantity' => 1],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.stock_site_id');
        $this->requestFor($admin, $company)
            ->patchJson("/api/v1/parts/products/{$product}/active", ['is_active' => false])
            ->assertOk();
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/sales', [...$base, 'id' => (string) Str::uuid(), 'lines' => [
                ['product_id' => $product, 'stock_site_id' => $store->id, 'quantity' => 1],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.product_id');

        // Le vendeur ne gère ni le catalogue ni le stock.
        $this->requestFor($seller, $company)
            ->postJson('/api/v1/parts/stock/receipts', ['site_id' => $store->id, 'reference' => 'X', 'lines' => [['product_id' => $product, 'quantity' => 1]]])
            ->assertForbidden();
        $this->requestFor($seller, $company)
            ->patchJson("/api/v1/parts/products/{$product}", ['sku' => 'X', 'name' => 'X', 'unit' => 'pièce', 'retail_price_htg' => '1', 'reorder_level' => 0])
            ->assertForbidden();
    }

    public function test_stock_adjustment_needs_a_reason_and_a_real_difference(): void
    {
        [$company, $store] = $this->company('AUTO');
        $admin = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $product = $this->product($admin, $company, 'BOUGIE-01', '250', null, $store, 5);

        $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/stock/adjustments', ['product_id' => $product, 'site_id' => $store->id, 'counted_quantity' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/stock/adjustments', ['product_id' => $product, 'site_id' => $store->id, 'counted_quantity' => 5, 'reason' => 'Inventaire'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counted_quantity');
        $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/stock/adjustments', ['product_id' => $product, 'site_id' => $store->id, 'counted_quantity' => 4, 'reason' => 'Pièce cassée'])
            ->assertCreated()
            ->assertJsonPath('data.quantity', -1)
            ->assertJsonPath('data.balance_after', 4);

        // Prix de gros plus élevé que le détail : refusé.
        $this->requestFor($admin, $company)
            ->patchJson("/api/v1/parts/products/{$product}", [
                'sku' => 'BOUGIE-01', 'name' => 'Bougie', 'unit' => 'pièce',
                'retail_price_htg' => '250', 'wholesale_price_htg' => '260', 'reorder_level' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wholesale_price_htg');

        $journal = $this->requestFor($admin, $company)
            ->getJson('/api/v1/parts/stock/movements')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');
        self::assertContains('Ajustement d’inventaire', array_column($journal, 'label'));
    }

    public function test_companies_and_stores_stay_isolated(): void
    {
        [$company, $store, $warehouse] = $this->company('AUTO');
        [$other, $otherStore] = $this->company('MOTO');
        $admin = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $otherAdmin = $this->user($other, RoleProfiles::permissions('auto_parts_administrator'));
        $product = $this->product($admin, $company, 'PNEU-90', '2500', null, $store, 3);

        // Un article d'une autre société est introuvable.
        $this->requestFor($otherAdmin, $other)
            ->getJson("/api/v1/parts/products/{$product}")
            ->assertNotFound();
        $this->requestFor($otherAdmin, $other)
            ->getJson('/api/v1/parts/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->requestFor($otherAdmin, $other)
            ->postJson('/api/v1/parts/stock/receipts', ['site_id' => $store->id, 'reference' => 'X', 'lines' => [['product_id' => $product, 'quantity' => 1]]])
            ->assertNotFound();

        // Un vendeur limité au magasin ne voit ni ne vend depuis l'entrepôt.
        $limited = $this->user($company, RoleProfiles::permissions('auto_parts_seller'), [$store->id]);
        $stock = $this->requestFor($limited, $company)
            ->getJson("/api/v1/parts/products/{$product}")
            ->assertOk()
            ->json('data.stock');
        self::assertSame([$store->id], array_column($stock, 'site_id'));
        $register = $this->register($company, $store, 'PARTS-01');
        $this->requestFor($limited, $company)
            ->postJson("/api/v1/cash/registers/{$register->id}/sessions", ['opening_usd' => '0', 'opening_htg' => '0'])
            ->assertCreated();
        $this->requestFor($limited, $company)
            ->postJson('/api/v1/parts/sales', [
                'id' => (string) Str::uuid(),
                'site_id' => $store->id,
                'cash_register_id' => $register->id,
                'price_list' => 'retail',
                'amount_received' => '2500',
                'lines' => [['product_id' => $product, 'stock_site_id' => $warehouse->id, 'quantity' => 1]],
            ])
            ->assertForbidden();

        // Une société Car Rental n'a pas de catalogue de pièces, même pour un accès complet.
        $rental = Company::query()->create(['code' => 'RENT', 'legal_name' => 'Rent S.A.', 'display_name' => 'Rent', 'base_currency' => 'USD']);
        $rentalToken = $this->user($rental, ['*']);
        $this->requestFor($rentalToken, $rental)
            ->getJson('/api/v1/parts/products')
            ->assertNotFound();

        // Une vente de l'autre société n'est pas lisible.
        $otherRegister = $this->register($other, $otherStore, 'MOTO-01');
        $otherProduct = $this->product($otherAdmin, $other, 'PNEU-90', '2600', null, $otherStore, 1);
        $this->requestFor($otherAdmin, $other)
            ->postJson("/api/v1/cash/registers/{$otherRegister->id}/sessions", ['opening_usd' => '0', 'opening_htg' => '0'])
            ->assertCreated();
        $otherSale = $this->requestFor($otherAdmin, $other)
            ->postJson('/api/v1/parts/sales', [
                'id' => (string) Str::uuid(),
                'site_id' => $otherStore->id,
                'cash_register_id' => $otherRegister->id,
                'price_list' => 'retail',
                'amount_received' => '2600',
                'lines' => [['product_id' => $otherProduct, 'stock_site_id' => $otherStore->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.number', '0000 0001')
            ->json('data.id');
        $this->requestFor($admin, $company)
            ->getJson("/api/v1/parts/sales/{$otherSale}")
            ->assertNotFound();
    }

    public function test_owner_configures_an_auto_parts_company_with_its_own_profiles(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['is_active' => true, 'system_role' => 'owner']);
        [, $token] = ApiAccessToken::issueFor($owner, Request::create('/api/v1/auth/login', 'POST'));

        $company = $this->withToken($token)
            ->postJson('/api/v1/system/configuration/companies', [
                'code' => 'AUTO-PARTS',
                'legal_name' => 'Clientèle Auto Parts S.A.',
                'display_name' => 'Clientèle Auto Parts',
                'base_currency' => 'HTG',
                'activity' => 'auto_parts',
            ])
            ->assertCreated()
            ->assertJsonPath('data.activity', 'auto_parts')
            ->assertJsonPath('data.role_options.1.key', 'auto_parts_seller')
            ->json('data.id');

        $site = $this->withToken($token)
            ->postJson("/api/v1/system/configuration/companies/{$company}/sites", [
                'code' => 'MAG-1',
                'name' => 'Magasin Cap-Haïtien',
                'address' => 'Rue 24, Cap-Haïtien',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'store')
            ->json('data.id');
        $this->withToken($token)
            ->patchJson("/api/v1/system/configuration/companies/{$company}/sites/{$site}/kind", ['kind' => 'warehouse'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'warehouse');

        $user = [
            'name' => 'Vendeur Comptoir',
            'email' => 'vendeur@example.test',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
            'site_scope' => 'all',
        ];
        $this->withToken($token)
            ->postJson("/api/v1/system/configuration/companies/{$company}/users", [...$user, 'role_key' => 'car_rental_agent'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_key');
        $this->withToken($token)
            ->postJson("/api/v1/system/configuration/companies/{$company}/users", [...$user, 'role_key' => 'auto_parts_seller'])
            ->assertCreated();

        // Un vendeur actif empêche de repasser la société en Car Rental.
        $this->withToken($token)
            ->patchJson("/api/v1/system/configuration/companies/{$company}/activity", ['activity' => 'car_rental'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('activity');
        self::assertSame('auto_parts', Company::query()->whereKey($company)->value('activity'));
    }

    /** @return array{0: Company, 1: Site, 2: Site} */
    private function company(string $code): array
    {
        $company = Company::query()->create([
            'code' => $code,
            'legal_name' => "Clientèle {$code} S.A.",
            'display_name' => "Clientèle {$code}",
            'base_currency' => 'HTG',
            'activity' => 'auto_parts',
        ]);
        $store = Site::query()->create([
            'company_id' => $company->id,
            'code' => $code . '-MAG',
            'name' => 'Magasin ' . $code,
            'address' => 'Cap-Haïtien',
            'kind' => 'store',
        ]);
        $warehouse = Site::query()->create([
            'company_id' => $company->id,
            'code' => $code . '-ENT',
            'name' => 'Entrepôt ' . $code,
            'address' => 'Limonade',
            'kind' => 'warehouse',
        ]);

        return [$company, $store, $warehouse];
    }

    /** @param array<int, string> $permissions @param array<int, string> $siteIds */
    private function user(Company $company, array $permissions, array $siteIds = []): string
    {
        $user = User::factory()->create(['is_active' => true]);
        $access = CompanyUserAccess::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'auto_parts_seller',
            'site_scope' => $siteIds === [] ? 'all' : 'selected',
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        foreach ($siteIds as $siteId) {
            CompanyUserSiteAccess::query()->create([
                'company_id' => $company->id,
                'company_user_access_id' => $access->id,
                'site_id' => $siteId,
                'is_active' => true,
            ]);
        }

        [, $token] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        return $token;
    }

    private function register(Company $company, Site $site, string $code): CashRegister
    {
        return CashRegister::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $code,
            'name' => 'Caisse ' . $code,
            'is_active' => true,
        ]);
    }

    private function product(string $token, Company $company, string $sku, string $retail, ?string $wholesale, Site $site, int $quantity): string
    {
        return $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/products', [
                'sku' => $sku,
                'name' => 'Article ' . $sku,
                'unit' => 'pièce',
                'retail_price_htg' => $retail,
                'wholesale_price_htg' => $wholesale,
                'reorder_level' => 1,
                'initial_site_id' => $site->id,
                'initial_quantity' => $quantity,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function requestFor(string $token, Company $company): self
    {
        return $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id);
    }
}
