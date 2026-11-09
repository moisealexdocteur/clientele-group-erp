<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsSale;
use App\Support\AutoParts\SalePresenter;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vente et données du reçu 80 mm. Une vente d'une autre société ou d'un magasin non autorisé est introuvable. */
final class ShowSale extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly SalePresenter $presenter,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request, string $sale): JsonResponse
    {
        $company = $this->company($request);
        $model = PartsSale::query()->where('company_id', $company->id)->whereKey($sale)->first();
        abort_if($model === null, 404, 'Vente introuvable.');
        $this->siteAuthorizer->siteFor($company, $this->access($request), $model->site_id);

        return response()->json(['data' => $this->presenter->detail($model, $company)]);
    }
}
