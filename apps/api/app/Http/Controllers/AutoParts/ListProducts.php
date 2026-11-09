<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Models\PartsProductFitment;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Catalogue : recherche par référence, code-barres, désignation, marque,
 * catégorie ou compatibilité (marque et modèle de motocyclette).
 * Sans recherche, les articles actifs sont listés par désignation.
 */
final class ListProducts extends Controller
{
    use ResolvesCompanyAccess;

    private const LIMIT = 100;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly PartsPresenter $presenter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'include_inactive' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
        ]);

        $term = Str::lower(trim((string) ($data['q'] ?? '')));
        $query = PartsProduct::query()
            ->where('company_id', $company->id)
            ->when(! ($data['include_inactive'] ?? false), static fn ($builder) => $builder->where('is_active', true));

        if ($term !== '') {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $query->where(static function ($builder) use ($like, $company): void {
                $builder->whereRaw('lower(sku) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(barcode, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(brand, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(category, \'\')) like ?', [$like])
                    ->orWhereIn('id', PartsProductFitment::query()
                        ->where('company_id', $company->id)
                        ->where(static fn ($fit) => $fit
                            ->whereRaw('lower(make) like ?', [$like])
                            ->orWhereRaw('lower(coalesce(model, \'\')) like ?', [$like]))
                        ->select('product_id'));
            });
        }

        $total = (clone $query)->count();
        $products = $query->orderBy('name')->limit($data['low_stock'] ?? false ? 1000 : self::LIMIT)->get();
        $rows = $this->presenter->products($products, $access, $this->lookup->stockSites($company, $access));

        if ($data['low_stock'] ?? false) {
            $rows = $rows->filter(static fn (array $row): bool => $row['is_low'])->values();
            $total = $rows->count();
            $rows = $rows->take(self::LIMIT)->values();
        }

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'limit' => self::LIMIT],
        ]);
    }
}
