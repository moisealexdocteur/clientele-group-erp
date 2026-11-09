<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\MotoOrder;
use App\Models\Site;
use App\Models\User;
use App\Support\AutoParts\MotoRules;
use App\Support\RoleProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MotoSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_orders_get_motos_in_payment_order_and_are_delivered_with_a_receipt(): void
    {
        [$company, $store, $warehouse] = $this->company('AUTO');
        $admin = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $seller = $this->user($company, RoleProfiles::permissions('auto_parts_seller'));
        $model = $this->model($admin, $company, 'HJ-125A-D', '1450.00', 'USD');

        // Un vendeur enregistre trois commandes ; la deuxième a été payée en premier.
        $late = $this->order($seller, $company, $store, $model, ['customer_name' => 'Client tardif', 'deposit_date' => $this->daysAgo(3), 'deposit_reference' => 'SG-3']);
        $first = $this->order($seller, $company, $store, $model, ['customer_name' => 'Premier client', 'deposit_date' => $this->daysAgo(7), 'deposit_reference' => 'SG-1']);
        $red = $this->order($seller, $company, $store, $model, ['customer_name' => 'Client rouge', 'deposit_date' => $this->daysAgo(5), 'deposit_reference' => 'SG-2', 'preferred_color' => 'Rouge Sang']);

        // Le vendeur ne vérifie pas les dépôts.
        $this->requestFor($seller, $company)
            ->postJson("/api/v1/parts/motos/orders/{$first}/verify", ['confirmed' => true])
            ->assertForbidden();

        foreach ([$late, $first, $red] as $order) {
            $this->requestFor($admin, $company)
                ->postJson("/api/v1/parts/motos/orders/{$order}/verify", ['confirmed' => true])
                ->assertOk()
                ->assertJsonPath('data.status', 'paid');
        }

        $this->requestFor($admin, $company)
            ->getJson('/api/v1/parts/motos/orders?status=paid')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.0.queue_position', 1)
            ->assertJsonPath('data.1.id', $red)
            ->assertJsonPath('data.2.id', $late)
            ->assertJsonPath('meta.counts.paid', 3);

        // Lot de deux motos noires : le premier payé est servi, le client « rouge » attend sa couleur.
        $batch = $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/motos/batches', [
                'site_id' => $warehouse->id,
                'reference' => 'NOVAMAT BL-2026-77',
                'units' => [
                    $this->unit($model, 'lzl12p1a8the50741', 'HJ157FMI260550741', 'Noir', false),
                    $this->unit($model, 'LZL12P1A8THE50742', 'HJ157FMI260550742', 'Noir', true),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.assigned', 2)
            ->assertJsonPath('data.units.0.frame_number', 'LZL12P1A8THE50741')
            ->json('data');

        $assigned = $this->requestFor($seller, $company)
            ->getJson('/api/v1/parts/motos/orders?status=assigned')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');
        self::assertSame([$first, $late], array_column($assigned, 'id'));
        $this->requestFor($seller, $company)
            ->getJson("/api/v1/parts/motos/orders/{$red}")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.queue_position', 1);

        // Remise : contrôle incomplet refusé.
        $delivery = [
            'deposit_slip_presented' => true,
            'id_document_type' => 'cin',
            'id_document_number' => '01-02-99-1990-01-00001',
            'customer_address' => 'Rue 15, Cap-Haïtien',
            'checklist' => collect(MotoRules::CHECKLIST)->map(static fn (): bool => true)->all(),
            'accessories' => collect(MotoRules::ACCESSORIES)->map(static fn (): bool => true)->all(),
            'customs_document_delivered' => true,
            'final_sale_acknowledged' => true,
        ];
        $this->requestFor($seller, $company)
            ->postJson("/api/v1/parts/motos/orders/{$first}/deliver", [...$delivery, 'checklist' => [...$delivery['checklist'], 'horn' => false]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('checklist');

        // La première moto n'a pas son document de dédouanement : il ne peut pas être déclaré remis.
        $this->requestFor($seller, $company)
            ->postJson("/api/v1/parts/motos/orders/{$first}/deliver", $delivery)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customs_document_delivered');

        $delivered = $this->requestFor($seller, $company)
            ->postJson("/api/v1/parts/motos/orders/{$first}/deliver", [...$delivery, 'customs_document_delivered' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.receipt_number', '0000 0001')
            ->assertJsonPath('data.unit.frame_number', 'LZL12P1A8THE50741')
            ->assertJsonPath('data.customs_document_pending', true)
            ->assertJsonPath('data.id_document_number', '01-02-99-1990-01-00001')
            ->json('data');

        $this->requestFor($seller, $company)
            ->getJson('/api/v1/parts/motos/orders?status=customs_pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first);
        $this->requestFor($seller, $company)
            ->postJson("/api/v1/parts/motos/orders/{$first}/customs-document", ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('data.customs_document_delivered', true);

        // Reçu et certificat : impressions journalisées, QR vérifiable.
        foreach (['receipt', 'certificate'] as $document) {
            $this->requestFor($seller, $company)
                ->postJson("/api/v1/parts/motos/orders/{$first}/prints", ['document' => $document])
                ->assertOk();
        }
        self::assertTrue(AuditEvent::query()->where('event_type', 'parts.moto_certificate_printed')->exists());
        parse_str((string) parse_url((string) $delivered['verification_url'], PHP_URL_QUERY), $query);
        $this->getJson('/api/v1/public/receipts/AUTO/00000001?s=' . $query['s'])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('amount', '1450.00')
            ->assertJsonPath('currency', 'USD');
    }

    public function test_payment_must_cover_the_price_and_converts_at_the_group_rate(): void
    {
        [$company, $store] = $this->company('AUTO');
        $admin = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $model = $this->model($admin, $company, 'HJ-150-10', '1500.00', 'USD');
        DB::table('group_exchange_rates')->insert([
            'id' => (string) Str::uuid(),
            'rate_htg_per_usd' => '132.0000',
            'effective_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1 500 USD x 132 = 198 000 HTG : 190 000 HTG ne suffisent pas.
        $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/motos/orders', $this->orderPayload($store, $model, ['payment_currency' => 'HTG', 'payment_amount' => '190000']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_amount');

        $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/motos/orders', $this->orderPayload($store, $model, ['payment_currency' => 'HTG', 'payment_amount' => '198000']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.amount_in_price_currency', '1500.00')
            ->assertJsonPath('data.exchange_rate_htg_per_usd', '132.0000');

        // Un modèle retiré n'est plus proposé.
        $this->requestFor($admin, $company)
            ->patchJson("/api/v1/parts/motos/models/{$model}", ['make' => 'Haojin', 'model' => 'HJ-150-10', 'cylinders' => 1, 'price' => '1500', 'price_currency' => 'USD', 'is_active' => false])
            ->assertOk();
        $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/motos/orders', $this->orderPayload($store, $model, ['payment_amount' => '1500']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('model_id');
    }

    public function test_manual_reassignment_and_cancellation_are_traced(): void
    {
        [$company, $store] = $this->company('AUTO');
        $admin = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $model = $this->model($admin, $company, 'HJ-125A-D', '1450.00', 'USD');
        $first = $this->order($admin, $company, $store, $model, ['deposit_date' => $this->daysAgo(7)]);
        $second = $this->order($admin, $company, $store, $model, ['deposit_date' => $this->daysAgo(6)]);
        foreach ([$first, $second] as $order) {
            $this->requestFor($admin, $company)->postJson("/api/v1/parts/motos/orders/{$order}/verify", ['confirmed' => true])->assertOk();
        }

        $unit = $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/motos/batches', [
                'site_id' => $store->id,
                'reference' => 'NOVAMAT BL-1',
                'units' => [$this->unit($model, 'FRAME-1', 'ENGINE-1', 'Bleu', true)],
            ])
            ->assertCreated()
            ->json('data.units.0.id');

        // La moto passe à la deuxième commande avec un motif ; la première revient en attente.
        $this->requestFor($admin, $company)
            ->postJson("/api/v1/parts/motos/orders/{$second}/assign", ['unit_id' => $unit])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->requestFor($admin, $company)
            ->postJson("/api/v1/parts/motos/orders/{$second}/assign", ['unit_id' => $unit, 'reason' => 'Client prioritaire autorisé par la direction'])
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.unit.id', $unit);
        self::assertSame('paid', MotoOrder::query()->whereKey($first)->value('status'));

        // Annulation : la moto revient en stock et la première commande la reçoit.
        $this->requestFor($admin, $company)
            ->postJson("/api/v1/parts/motos/orders/{$second}/cancel", ['reason' => 'Remboursement demandé par le client'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->requestFor($admin, $company)->postJson('/api/v1/parts/motos/allocate')->assertOk()->assertJsonPath('data.assigned', 1);
        self::assertSame('assigned', MotoOrder::query()->whereKey($first)->value('status'));
        self::assertTrue(AuditEvent::query()->where('event_type', 'parts.moto_unassigned')->exists());

        // Un numéro de châssis déjà enregistré est refusé.
        $this->requestFor($admin, $company)
            ->postJson('/api/v1/parts/motos/batches', [
                'site_id' => $store->id,
                'reference' => 'NOVAMAT BL-2',
                'units' => [$this->unit($model, 'frame-1', 'ENGINE-2', 'Bleu', true)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('units.0.frame_number');
    }

    public function test_orders_stay_within_their_company_and_address(): void
    {
        [$company, $store, $warehouse] = $this->company('AUTO');
        [$other] = $this->company('MOTO');
        $admin = $this->user($company, RoleProfiles::permissions('auto_parts_administrator'));
        $otherAdmin = $this->user($other, RoleProfiles::permissions('auto_parts_administrator'));
        $model = $this->model($admin, $company, 'HJ-125A-D', '1450.00', 'USD');
        $order = $this->order($admin, $company, $store, $model, []);

        $this->requestFor($otherAdmin, $other)->getJson("/api/v1/parts/motos/orders/{$order}")->assertNotFound();
        $this->requestFor($otherAdmin, $other)
            ->getJson('/api/v1/parts/motos/orders?status=submitted')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Un vendeur limité à l'entrepôt ne voit pas la commande retirée au magasin.
        $limited = $this->user($company, RoleProfiles::permissions('auto_parts_seller'), [$warehouse->id]);
        $this->requestFor($limited, $company)->getJson("/api/v1/parts/motos/orders/{$order}")->assertForbidden();
        $this->requestFor($limited, $company)
            ->getJson('/api/v1/parts/motos/orders?status=submitted')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Le magasinier ne voit ni la pièce d'identité ni la fiche de dépôt.
        $storekeeper = $this->user($company, RoleProfiles::permissions('auto_parts_storekeeper'));
        $this->requestFor($storekeeper, $company)
            ->getJson("/api/v1/parts/motos/orders/{$order}")
            ->assertOk()
            ->assertJsonPath('data.id_document_number', null)
            ->assertJsonPath('data.deposit_proof_url', null);
        $this->requestFor($storekeeper, $company)
            ->postJson('/api/v1/parts/motos/orders', $this->orderPayload($store, $model, []))
            ->assertForbidden();
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
        $store = Site::query()->create(['company_id' => $company->id, 'code' => $code . '-MAG', 'name' => 'Rue 4 A, Cap-Haïtien', 'address' => 'Rue 4 A, Cap-Haïtien', 'kind' => 'store']);
        $warehouse = Site::query()->create(['company_id' => $company->id, 'code' => $code . '-PP', 'name' => 'Pont Parois', 'address' => 'Route Nationale 6', 'kind' => 'warehouse']);

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

    private function model(string $token, Company $company, string $name, string $price, string $currency): string
    {
        return $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/motos/models', [
                'make' => 'Haojin',
                'model' => $name,
                'displacement_cc' => 125,
                'cylinders' => 1,
                'price' => $price,
                'price_currency' => $currency,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function orderPayload(Site $site, string $model, array $overrides): array
    {
        return [
            'site_id' => $site->id,
            'model_id' => $model,
            'customer_name' => 'Jean Baptiste',
            'customer_phone' => '+509 3700 0000',
            'payment_currency' => 'USD',
            'payment_amount' => '1450.00',
            'deposit_reference' => 'SG-' . Str::random(6),
            'deposit_date' => $this->daysAgo(6),
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function order(string $token, Company $company, Site $site, string $model, array $overrides): string
    {
        return $this->requestFor($token, $company)
            ->postJson('/api/v1/parts/motos/orders', $this->orderPayload($site, $model, $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    /** @return array<string, mixed> */
    private function unit(string $model, string $frame, string $engine, string $color, bool $customs): array
    {
        return [
            'model_id' => $model,
            'model_year' => 2026,
            'frame_number' => $frame,
            'engine_number' => $engine,
            'color' => $color,
            'customs_document_available' => $customs,
        ];
    }

    /** Date de dépôt passée, relative à aujourd'hui. */
    private function daysAgo(int $days): string
    {
        return now('America/Port-au-Prince')->subDays($days)->format('Y-m-d');
    }

    private function requestFor(string $token, Company $company): self
    {
        return $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id);
    }
}
