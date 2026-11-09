<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoAllocator;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Annulation d'une commande non livrée, avec motif : dépôt introuvable,
 * demande du client ou remboursement. La moto attribuée revient en stock.
 * Le remboursement éventuel se fait hors de l'application.
 */
final class CancelMotoOrder extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
        private readonly MotoAllocator $allocator,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $order): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Indiquez le motif de l’annulation.']);

        $model = DB::transaction(function () use ($company, $access, $order, $actor, $data): MotoOrder {
            $model = $this->lookup->motoOrderFor($company, $access, $order, true);

            if (in_array($model->status, ['delivered', 'cancelled'], true)) {
                throw ValidationException::withMessages(['order' => 'Une commande livrée ou annulée ne peut pas être annulée.']);
            }

            $this->allocator->detach($company, $model, $actor, 'Annulation de la commande.');
            $model->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor?->id,
                'cancelled_at' => now()->utc(),
                'cancellation_reason' => trim($data['reason']),
            ])->save();

            $this->audit->record(
                eventType: 'parts.moto_order_cancelled',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: MotoOrder::class,
                subjectId: $model->id,
                metadata: ['reason' => trim($data['reason'])],
            );

            return $model;
        });

        return response()->json(['data' => $this->presenter->orderPayload($model, $company, $access, [], true)]);
    }
}
