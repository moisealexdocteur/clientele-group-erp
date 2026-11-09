<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoModel;
use App\Models\MotoOrder;
use App\Models\MotoUnit;
use App\Support\AutoParts\MotoPresenter;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock de motos par modèle : disponibles à la vente (en stock, non
 * attribuées), attribuées à un client payé, commandes payées en attente
 * d'une moto, avec le détail par emplacement et par couleur.
 */
final class ShowMotoStock extends Controller
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
        $siteIds = $this->siteAuthorizer->activeSiteIdsFor($company, $access);

        $units = MotoUnit::query()->with('site')
            ->where('company_id', $company->id)
            ->whereIn('status', ['in_stock', 'reserved'])
            ->whereIn('site_id', $siteIds)
            ->get();
        $waiting = MotoOrder::query()->where('company_id', $company->id)
            ->where('status', 'paid')
            ->selectRaw('model_id, count(*) as total')->groupBy('model_id')->pluck('total', 'model_id');

        $models = MotoModel::query()->where('company_id', $company->id)
            ->orderByDesc('is_active')->orderBy('make')->orderBy('model')->get();

        $rows = $models->map(function (MotoModel $model) use ($units, $waiting): array {
            $mine = $units->where('model_id', $model->id);
            $available = $mine->where('status', 'in_stock');
            $sites = $available->groupBy('site_id')->map(fn ($group) => ['name' => $group->first()->site?->name, 'count' => $group->count()])->values();
            $colors = $available->groupBy(fn ($unit) => mb_strtolower((string) $unit->color))->map(fn ($group) => ['color' => $group->first()->color, 'count' => $group->count()])->sortByDesc('count')->values();

            return [
                'model' => $this->presenter->modelPayload($model),
                'available' => $available->count(),
                'reserved' => $mine->where('status', 'reserved')->count(),
                'waiting' => (int) ($waiting[$model->id] ?? 0),
                'sites' => $sites,
                'colors' => $colors,
            ];
        })->filter(fn (array $row): bool => $row['model']['is_active'] || $row['available'] > 0 || $row['reserved'] > 0 || $row['waiting'] > 0)->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'available' => $rows->sum('available'),
                'reserved' => $rows->sum('reserved'),
                'waiting' => $rows->sum('waiting'),
            ],
        ]);
    }
}
