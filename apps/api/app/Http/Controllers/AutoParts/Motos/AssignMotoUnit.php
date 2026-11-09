<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Models\MotoUnit;
use App\Support\AutoParts\MotoAllocator;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changement manuel de l'attribution, avec motif journalisé : une autre
 * moto pour cette commande, ou retour de la commande en attente. Une moto
 * prise à une autre commande remet celle-ci en attente.
 */
final class AssignMotoUnit extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
        private readonly MotoAllocator $allocator,
    ) {
    }

    public function __invoke(Request $request, string $order): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $data = $request->validate([
            'unit_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'Indiquez le motif du changement d’attribution.']);

        $model = DB::transaction(function () use ($company, $access, $order, $actor, $data): MotoOrder {
            $model = $this->lookup->motoOrderFor($company, $access, $order, true);

            if (! in_array($model->status, ['paid', 'assigned'], true)) {
                throw ValidationException::withMessages(['order' => 'Seule une commande payée et non livrée peut recevoir une moto.']);
            }

            $reason = trim($data['reason']);
            $this->allocator->detach($company, $model, $actor, $reason);

            if (($data['unit_id'] ?? null) !== null) {
                $unit = MotoUnit::query()->where('company_id', $company->id)->whereKey($data['unit_id'])->lockForUpdate()->first();

                if ($unit === null || $unit->status === 'delivered') {
                    throw ValidationException::withMessages(['unit_id' => 'Cette moto n’est pas disponible.']);
                }
                if ($unit->model_id !== $model->model_id) {
                    throw ValidationException::withMessages(['unit_id' => 'Cette moto n’est pas du modèle commandé.']);
                }

                if ($unit->order_id !== null) {
                    $previous = MotoOrder::query()->where('company_id', $company->id)->whereKey($unit->order_id)->lockForUpdate()->first();
                    if ($previous !== null) {
                        $this->allocator->detach($company, $previous, $actor, $reason);
                    }
                    $unit->refresh();
                }

                $this->allocator->attach($company, $model->refresh(), $unit, $actor, $reason);
            }

            return $model->refresh();
        });

        return response()->json([
            'data' => $this->presenter->orderPayload($model, $company, $access, $this->presenter->queuePositions($company), true),
        ]);
    }
}
