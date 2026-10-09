<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalVehicleRules;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateVehicleCommercialTerms extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalVehicleRules $vehicleRules,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $vehicle): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->lookup->vehicleFor($company, $access, $vehicle);

        $data = $request->validate([
            'daily_rate_usd' => ['required', 'numeric', 'gt:0', new DecimalAmount()],
            'minimum_security_deposit_usd' => ['required', 'numeric', 'gte:0', new DecimalAmount()],
        ], $this->vehicleRules->vehicleValidationMessages());

        $dailyRate = Money::normalize((string) $data['daily_rate_usd']);
        $minimumDeposit = Money::normalize((string) $data['minimum_security_deposit_usd']);
        $changed = Money::toCents((string) $model->daily_rate_usd) !== Money::toCents($dailyRate)
            || Money::toCents((string) $model->minimum_security_deposit_usd) !== Money::toCents($minimumDeposit);

        $model->forceFill([
            'daily_rate_usd' => $dailyRate,
            'minimum_security_deposit_usd' => $minimumDeposit,
        ])->save();

        if ($changed) {
            $this->audit->record(
                eventType: 'car_rental.vehicle_commercial_terms_updated',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: CarRentalVehicle::class,
                subjectId: $model->id,
                metadata: [
                    'site_id' => $model->site_id,
                    'daily_rate_usd' => $model->daily_rate_usd,
                    'minimum_security_deposit_usd' => $model->minimum_security_deposit_usd,
                ],
            );
        }

        return response()->json([
            'data' => $this->presenter->vehiclePayload($model->load(['site', 'documents']), true, $company),
        ]);
    }
}
