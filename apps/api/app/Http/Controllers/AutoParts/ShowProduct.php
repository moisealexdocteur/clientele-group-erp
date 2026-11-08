<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsStockMovement;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fiche article : prix, compatibilités, stock par emplacement et derniers mouvements. */
final class ShowProduct extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly PartsPresenter $presenter,
    ) {
    }

    public function __invoke(Request $request, string $product): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $model = $this->lookup->productFor($company, $product);
        $sites = $this->lookup->stockSites($company, $access);
        $payload = $this->presenter->productPayload($model, $access, $sites);

        $payload['movements'] = $access->allows('parts.stock.read')
            ? PartsStockMovement::query()
                ->where('company_id', $company->id)
                ->where('product_id', $model->id)
                ->whereIn('site_id', $sites->pluck('id'))
                ->with(['site', 'recorder'])
                ->orderByDesc('occurred_at')
                ->limit(50)
                ->get()
                ->map(fn (PartsStockMovement $movement): array => $this->presenter->movementPayload($movement))
                ->values()
            : [];

        return response()->json(['data' => $payload]);
    }
}
