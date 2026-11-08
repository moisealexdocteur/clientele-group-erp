<?php

namespace App\Support\AutoParts;

use App\Models\CompanyUserAccess;
use App\Models\PartsProduct;
use App\Models\PartsProductFitment;
use App\Models\PartsStockLevel;
use App\Models\PartsStockMovement;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Présentation Auto Parts. Le prix de gros n'est montré qu'aux rôles qui
 * peuvent l'appliquer ou gérer le catalogue ; le stock seulement aux rôles
 * qui le consultent, et pour les emplacements autorisés.
 */
final class PartsPresenter
{
    public const MOVEMENT_LABELS = [
        'receipt' => 'Entrée de stock',
        'adjustment' => 'Ajustement d’inventaire',
        'transfer_out' => 'Transfert sortant',
        'transfer_in' => 'Transfert entrant',
        'sale' => 'Vente',
    ];

    /**
     * @param  Collection<int, PartsProduct>  $products
     * @param  Collection<int, Site>  $stockSites
     * @return Collection<int, array<string, mixed>>
     */
    public function products(Collection $products, CompanyUserAccess $access, Collection $stockSites): Collection
    {
        $levels = $access->allows('parts.stock.read') && $products->isNotEmpty()
            ? PartsStockLevel::query()
                ->whereIn('product_id', $products->pluck('id'))
                ->whereIn('site_id', $stockSites->pluck('id'))
                ->get(['product_id', 'site_id', 'quantity'])
                ->groupBy('product_id')
            : collect();

        return $products->map(fn (PartsProduct $product): array => $this->productPayload(
            $product,
            $access,
            $stockSites,
            $levels->get($product->id, collect()),
        ))->values();
    }

    /**
     * @param  Collection<int, Site>  $stockSites
     * @param  Collection<int, PartsStockLevel>|null  $levels
     * @return array<string, mixed>
     */
    public function productPayload(PartsProduct $product, CompanyUserAccess $access, Collection $stockSites, ?Collection $levels = null): array
    {
        $product->loadMissing('fitments');
        $seesWholesale = $access->allows('parts.sales.wholesale') || $access->allows('parts.catalog.manage');
        $payload = [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'category' => $product->category,
            'brand' => $product->brand,
            'unit' => $product->unit,
            'retail_price_htg' => (string) $product->retail_price_htg,
            'wholesale_price_htg' => $seesWholesale && $product->wholesale_price_htg !== null ? (string) $product->wholesale_price_htg : null,
            'has_wholesale_price' => $product->wholesale_price_htg !== null,
            'reorder_level' => $product->reorder_level,
            'notes' => $product->notes,
            'is_active' => $product->is_active,
            'photo_file_id' => $product->photo_file_id,
            'photo_url' => $product->photo_file_id === null ? null : '/api/v1/car-rental/files/' . $product->photo_file_id,
            'fitments' => $product->fitments->map(fn (PartsProductFitment $fitment): array => [
                'id' => $fitment->id,
                'make' => $fitment->make,
                'model' => $fitment->model,
                'year_from' => $fitment->year_from,
                'year_to' => $fitment->year_to,
                'label' => $this->fitmentLabel($fitment),
            ])->values(),
            'stock' => null,
            'total_stock' => null,
            'is_low' => false,
        ];

        if ($access->allows('parts.stock.read')) {
            $levels ??= PartsStockLevel::query()
                ->where('product_id', $product->id)
                ->whereIn('site_id', $stockSites->pluck('id'))
                ->get(['product_id', 'site_id', 'quantity']);
            $bySite = $levels->keyBy('site_id');
            $stock = $stockSites->map(static fn (Site $site): array => [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'kind' => $site->kind,
                'quantity' => (int) ($bySite->get($site->id)?->quantity ?? 0),
            ])->values();
            $total = (int) $stock->sum('quantity');
            $payload['stock'] = $stock;
            $payload['total_stock'] = $total;
            $payload['is_low'] = $product->is_active && $total <= $product->reorder_level;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function movementPayload(PartsStockMovement $movement): array
    {
        $movement->loadMissing(['site', 'recorder']);

        return [
            'id' => $movement->id,
            'kind' => $movement->kind,
            'label' => self::MOVEMENT_LABELS[$movement->kind] ?? $movement->kind,
            'site' => ['id' => $movement->site_id, 'name' => $movement->site?->name],
            'quantity' => $movement->quantity,
            'balance_after' => $movement->balance_after,
            'unit_cost_htg' => $movement->unit_cost_htg === null ? null : (string) $movement->unit_cost_htg,
            'reference' => $movement->reference,
            'note' => $movement->note,
            'sale_id' => $movement->sale_id,
            'recorded_by' => $movement->recorder?->name,
            'occurred_at' => $movement->occurred_at?->toIso8601String(),
        ];
    }

    public function fitmentLabel(PartsProductFitment $fitment): string
    {
        $years = match (true) {
            $fitment->year_from !== null && $fitment->year_to !== null && $fitment->year_from !== $fitment->year_to => " {$fitment->year_from}-{$fitment->year_to}",
            $fitment->year_from !== null => " {$fitment->year_from}",
            $fitment->year_to !== null => " jusqu’à {$fitment->year_to}",
            default => '',
        };

        return trim($fitment->make . ' ' . ($fitment->model ?? '')) . $years;
    }
}
