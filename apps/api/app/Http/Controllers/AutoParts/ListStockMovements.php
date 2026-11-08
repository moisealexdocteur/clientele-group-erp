<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsStockMovement;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal de stock des emplacements autorisés : sept derniers jours par
 * défaut, filtrable par emplacement, nature et période.
 */
final class ListStockMovements extends Controller
{
    use ResolvesCompanyAccess;

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
            'site_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', 'in:' . implode(',', PartsStockMovement::KINDS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $sites = filled($data['site_id'] ?? null)
            ? collect([$this->lookup->stockSiteFor($company, $access, (string) $data['site_id'])])
            : $this->lookup->stockSites($company, $access);
        $timezone = $company->timezone ?: 'America/Port-au-Prince';
        $to = isset($data['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $timezone)->endOfDay()
            : CarbonImmutable::now($timezone)->endOfDay();
        $from = isset($data['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $timezone)->startOfDay()
            : $to->subDays(6)->startOfDay();

        $movements = PartsStockMovement::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $sites->pluck('id'))
            ->when($data['kind'] ?? null, static fn ($query, $kind) => $query->where('kind', $kind))
            ->where('occurred_at', '>=', $from->utc())
            ->where('occurred_at', '<=', $to->utc())
            ->with(['site', 'recorder', 'product'])
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $movements->map(fn (PartsStockMovement $movement): array => [
                ...$this->presenter->movementPayload($movement),
                'product' => ['id' => $movement->product_id, 'sku' => $movement->product?->sku, 'name' => $movement->product?->name],
            ])->values(),
            'meta' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ]);
    }
}
