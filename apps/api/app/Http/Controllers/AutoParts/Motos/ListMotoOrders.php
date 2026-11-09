<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\MotoRules;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Commandes par état, dans les adresses autorisées. En attente : ordre de
 * paiement. Moto attribuée : liste des clients à appeler.
 */
final class ListMotoOrders extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly MotoPresenter $presenter,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...array_keys(MotoRules::STATUS_LABELS), 'customs_pending'])],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $status = $data['status'] ?? 'paid';
        $siteIds = $this->siteAuthorizer->activeSiteIdsFor($company, $access);

        $query = MotoOrder::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->with(['motoModel', 'unit.motoModel', 'unit.site', 'site']);

        if ($status === 'customs_pending') {
            $query->where('status', 'delivered')->where('customs_document_delivered', false);
        } else {
            $query->where('status', $status);
        }

        if (filled($data['q'] ?? null)) {
            $term = '%' . addcslashes(mb_strtolower(trim((string) $data['q'])), '%_\\') . '%';
            $query->where(static fn ($builder) => $builder
                ->whereRaw('lower(customer_name) like ?', [$term])
                ->orWhereRaw('customer_phone like ?', [$term])
                ->orWhere('number', 'like', str_replace(' ', '', $term))
                ->orWhereRaw('lower(deposit_reference) like ?', [$term]));
        }

        $orders = in_array($status, ['delivered', 'cancelled', 'customs_pending'], true)
            ? $query->orderByDesc('updated_at')->limit(200)->get()
            : $query->orderBy('deposit_date')->orderBy('submitted_at')->limit(500)->get();
        $positions = $status === 'paid' ? $this->presenter->queuePositions($company) : [];

        $counts = MotoOrder::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => $orders->map(fn (MotoOrder $order): array => $this->presenter->orderPayload($order, $company, $access, $positions))->values(),
            'meta' => [
                'status' => $status,
                'counts' => [
                    ...collect(array_keys(MotoRules::STATUS_LABELS))->mapWithKeys(static fn (string $key): array => [$key => (int) ($counts[$key] ?? 0)])->all(),
                    'customs_pending' => MotoOrder::query()
                        ->where('company_id', $company->id)
                        ->whereIn('site_id', $siteIds)
                        ->where('status', 'delivered')
                        ->where('customs_document_delivered', false)
                        ->count(),
                ],
            ],
        ]);
    }
}
