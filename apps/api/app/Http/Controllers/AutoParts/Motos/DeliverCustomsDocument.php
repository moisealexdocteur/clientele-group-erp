<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Models\MotoUnit;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Le client revient chercher le document de dédouanement qui manquait à la remise. */
final class DeliverCustomsDocument extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $order): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $request->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Confirmez la remise du document au client.']);

        $model = DB::transaction(function () use ($company, $access, $order, $actor): MotoOrder {
            $model = $this->lookup->motoOrderFor($company, $access, $order, true);

            if ($model->status !== 'delivered' || $model->customs_document_delivered !== false) {
                throw ValidationException::withMessages(['order' => 'Aucun document de dédouanement n’est à remettre pour cette commande.']);
            }

            // Le document est arrivé : la moto est marquée en conséquence.
            MotoUnit::query()->where('company_id', $company->id)->whereKey($model->unit_id)->update(['customs_document_available' => true]);
            $model->forceFill([
                'customs_document_delivered' => true,
                'customs_document_delivered_by' => $actor?->id,
                'customs_document_delivered_at' => now()->utc(),
            ])->save();

            $this->audit->record(
                eventType: 'parts.moto_customs_document_delivered',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: MotoOrder::class,
                subjectId: $model->id,
            );

            return $model;
        });

        return response()->json(['data' => $this->presenter->orderPayload($model, $company, $access, [], true)]);
    }
}
