<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoUnit;
use App\Support\AutoParts\MotoPresenter;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Motos reçues, disponibles par défaut, dans les adresses autorisées. */
final class ListMotoUnits extends Controller
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
            'status' => ['nullable', Rule::in(MotoUnit::STATUSES)],
            'model_id' => ['nullable', 'uuid'],
        ]);

        $units = MotoUnit::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $this->siteAuthorizer->activeSiteIdsFor($company, $access))
            ->where('status', $data['status'] ?? 'in_stock')
            ->when($data['model_id'] ?? null, static fn ($query, $model) => $query->where('model_id', $model))
            ->with(['motoModel', 'site'])
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        return response()->json(['data' => $units->map(fn (MotoUnit $unit): array => $this->presenter->unitPayload($unit))->values()]);
    }
}
