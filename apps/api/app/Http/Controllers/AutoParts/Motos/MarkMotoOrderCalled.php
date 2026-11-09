<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Le client d'une moto attribuée a été appelé pour venir la chercher. */
final class MarkMotoOrderCalled extends Controller
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
        $model = $this->lookup->motoOrderFor($company, $access, $order);

        if ($model->status !== 'assigned') {
            throw ValidationException::withMessages(['order' => 'Seul le client d’une moto attribuée peut être appelé.']);
        }

        $model->forceFill(['called_by' => $actor?->id, 'called_at' => now()->utc()])->save();
        $this->audit->record(
            eventType: 'parts.moto_customer_called',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: MotoOrder::class,
            subjectId: $model->id,
        );

        return response()->json(['data' => $this->presenter->orderPayload($model, $company, $access, [], true)]);
    }
}
