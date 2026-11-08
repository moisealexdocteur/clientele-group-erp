<?php

namespace App\Support\AutoParts;

use App\Models\PartsProduct;
use App\Models\PartsStockLevel;
use App\Models\PartsStockMovement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seul point d'écriture du stock : chaque variation verrouille la ligne de
 * stock, refuse un stock négatif et ajoute une ligne immuable au journal
 * avec le solde obtenu. À appeler dans la transaction de l'opération.
 */
final class StockLedger
{
    /**
     * @param  array{unit_cost_htg?: string|null, reference?: string|null, note?: string|null, transfer_id?: string|null, sale_id?: string|null}  $details
     */
    public function apply(
        PartsProduct $product,
        Site $site,
        int $delta,
        string $kind,
        ?User $actor,
        array $details = [],
        string $field = 'quantity',
    ): PartsStockMovement {
        if (! in_array($kind, PartsStockMovement::KINDS, true)) {
            throw new \InvalidArgumentException("Mouvement de stock inconnu : {$kind}");
        }

        $level = $this->lockedLevel($product, $site);
        $balance = $level->quantity + $delta;

        if ($balance < 0) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'Stock insuffisant pour %s à %s : %d disponible%s, %d demandé%s.',
                    $product->sku,
                    $site->name,
                    $level->quantity,
                    $level->quantity > 1 ? 's' : '',
                    -$delta,
                    -$delta > 1 ? 's' : '',
                ),
            ]);
        }

        $level->forceFill(['quantity' => $balance])->save();

        return PartsStockMovement::query()->create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'site_id' => $site->id,
            'kind' => $kind,
            'quantity' => $delta,
            'balance_after' => $balance,
            'unit_cost_htg' => $details['unit_cost_htg'] ?? null,
            'reference' => $details['reference'] ?? null,
            'note' => $details['note'] ?? null,
            'transfer_id' => $details['transfer_id'] ?? null,
            'sale_id' => $details['sale_id'] ?? null,
            'recorded_by' => $actor?->id,
            'occurred_at' => now()->utc(),
        ]);
    }

    public function quantity(PartsProduct $product, Site $site): int
    {
        return (int) PartsStockLevel::query()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('site_id', $site->id)
            ->value('quantity');
    }

    public function newTransferId(): string
    {
        return (string) Str::uuid();
    }

    private function lockedLevel(PartsProduct $product, Site $site): PartsStockLevel
    {
        PartsStockLevel::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'site_id' => $site->id,
            'quantity' => 0,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        return PartsStockLevel::query()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
