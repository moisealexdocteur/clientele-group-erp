<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Support\Cash\CashPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowCashSession extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CashPresenter $presenter,
    ) {
    }

    public function __invoke(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $this->presenter->assertCanSeeCash($access);
        $model = $this->presenter->sessionFor($company, $access, $session);

        return response()->json([
            'data' => $this->presenter->sessionPayload($model, $access, $request->user(), true),
        ]);
    }
}
