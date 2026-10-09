<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicleDocument;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListVehicleDocuments extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
    ) {
    }

    public function __invoke(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $model = $this->lookup->vehicleFor($company, $access, $vehicle);

        $documents = CarRentalVehicleDocument::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $model->id)
            ->orderBy('document_type')
            ->get();

        return response()->json([
            'data' => $documents
                ->map(fn (CarRentalVehicleDocument $document): array => $this->presenter->vehicleDocumentPayload($document, $company))
                ->values(),
        ]);
    }
}
