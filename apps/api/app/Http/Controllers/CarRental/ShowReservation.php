<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowReservation extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
    ) {
    }

    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $model = $this->lookup->reservationFor(
            $company,
            $access,
            $reservation,
            ['vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections.photos', 'invoice'],
        );

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
        ]);
    }
}
