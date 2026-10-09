<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Models\CarRentalVehicle;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class UpdateVehicleActive extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Met un véhicule hors flotte ou l'y remet. Un véhicule qui a une
     * réservation à venir ou une location en cours ne peut pas être désactivé.
     */
    public function __invoke(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->lookup->vehicleFor($company, $access, $vehicle);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $active = (bool) $data['is_active'];

        if (! $active) {
            $open = CarRentalReservation::query()
                ->where('company_id', $company->id)
                ->where('vehicle_id', $model->id)
                ->whereIn('state', ['reserved', 'checked_out'])
                ->count();

            if ($open > 0) {
                throw ValidationException::withMessages([
                    'is_active' => $open > 1
                        ? "Ce véhicule a {$open} réservations ou locations en cours. Modifiez-les avant de le désactiver."
                        : 'Ce véhicule a une réservation ou une location en cours. Modifiez-la avant de le désactiver.',
                ]);
            }
        }

        if ($model->is_active !== $active) {
            $model->forceFill(['is_active' => $active])->save();
            $this->audit->record(
                eventType: $active ? 'car_rental.vehicle_activated' : 'car_rental.vehicle_deactivated',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: CarRentalVehicle::class,
                subjectId: $model->id,
                metadata: ['site_id' => $model->site_id],
            );
        }

        return response()->json([
            'data' => $this->presenter->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }
}
