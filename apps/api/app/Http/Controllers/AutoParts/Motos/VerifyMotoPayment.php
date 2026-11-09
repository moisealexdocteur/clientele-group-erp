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
 * Vérification du dépôt bancaire par un administrateur : la commande entre
 * dans la liste d'attente à son rang de paiement et reçoit une moto si une
 * moto du modèle est disponible.
 */
final class VerifyMotoPayment extends Controller
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

        $request->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Confirmez que le dépôt figure sur le relevé de la banque.']);

        $model = DB::transaction(function () use ($company, $access, $order, $actor): MotoOrder {
            $model = $this->lookup->motoOrderFor($company, $access, $order, true);

            if ($model->status !== 'submitted') {
                throw ValidationException::withMessages(['order' => 'Ce dépôt a déjà été traité.']);
            }

            $model->forceFill(['status' => 'paid', 'verified_by' => $actor?->id, 'verified_at' => now()->utc()])->save();
            $this->audit->record(
                eventType: 'parts.moto_payment_verified',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: MotoOrder::class,
                subjectId: $model->id,
                metadata: ['deposit_reference' => $model->deposit_reference, 'amount' => (string) $model->payment_amount, 'currency' => $model->payment_currency],
            );

            $this->allocator->allocate($company, $actor);

            return $model->refresh();
        });

        return response()->json([
            'data' => $this->presenter->orderPayload($model, $company, $access, $this->presenter->queuePositions($company), true),
        ]);
    }
}
