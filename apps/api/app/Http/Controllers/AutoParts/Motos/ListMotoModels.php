<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoModel;
use App\Models\MotoUnit;
use App\Support\AutoParts\MotoPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Modèles vendus avec leur prix et le nombre de motos disponibles. */
final class ListMotoModels extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(private readonly MotoPresenter $presenter)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $available = MotoUnit::query()
            ->where('company_id', $company->id)
            ->where('status', 'in_stock')
            ->selectRaw('model_id, count(*) as total')
            ->groupBy('model_id')
            ->pluck('total', 'model_id');

        return response()->json([
            'data' => MotoModel::query()
                ->where('company_id', $company->id)
                ->orderByDesc('is_active')
                ->orderBy('make')
                ->orderBy('model')
                ->get()
                ->map(fn (MotoModel $model): array => [
                    ...$this->presenter->modelPayload($model),
                    'available' => (int) ($available[$model->id] ?? 0),
                ])
                ->values(),
        ]);
    }
}
