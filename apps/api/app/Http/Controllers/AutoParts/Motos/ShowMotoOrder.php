<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Détail d'une commande, avec les données du reçu et du certificat après la remise. */
final class ShowMotoOrder extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
    ) {
    }

    public function __invoke(Request $request, string $order): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $model = $this->lookup->motoOrderFor($company, $access, $order);

        return response()->json([
            'data' => $this->presenter->orderPayload($model, $company, $access, $this->presenter->queuePositions($company), true),
        ]);
    }
}
