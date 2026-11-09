<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsSale;
use App\Support\AutoParts\SalePresenter;
use App\Support\Cash\CashPresenter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ventes du jour (heure de Cap-Haïtien) dans les magasins autorisés, sans nom de client. */
final class ListSales extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly SalePresenter $presenter,
        private readonly CashPresenter $cashPresenter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'site_id' => ['nullable', 'uuid'],
        ]);

        [$day, $start, $end] = $this->cashPresenter->day($company, $data['date'] ?? null);
        $siteIds = $this->cashPresenter->siteIds($company, $access, $data['site_id'] ?? null);

        $sales = PartsSale::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->where('sold_at', '>=', $start->utc())
            ->where('sold_at', '<', $end->utc())
            ->with(['site', 'cashRegister', 'seller'])
            ->orderByDesc('sold_at')
            ->get();

        return response()->json([
            'data' => $sales->map(fn (PartsSale $sale): array => $this->presenter->summary($sale))->values(),
            'meta' => [
                'date' => $day,
                'count' => $sales->count(),
                'item_count' => (int) $sales->sum('item_count'),
                'total' => Money::fromCents(Money::sumCents($sales->map(static fn (PartsSale $sale): string => (string) $sale->total))),
                'currency' => 'HTG',
            ],
        ]);
    }
}
