<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Support\CarRentalAvailabilityService;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalSchedule;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CheckAvailability extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalSchedule $schedule,
        private readonly CarRentalAvailabilityService $availability,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'pickup_at' => ['required', 'date'],
            'due_at' => ['required', 'date'],
            'category' => ['nullable', Rule::in(CarRentalVehicle::CATEGORIES)],
        ]);

        $site = $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        [$pickupAt, $dueAt] = $this->schedule->interval($company, $data['pickup_at'], $data['due_at']);

        $vehicles = $this->availability->availableVehicles(
            $company->id,
            $site->id,
            $pickupAt,
            $dueAt,
            $data['category'] ?? null,
        );

        return response()->json([
            'data' => $vehicles->map(fn (CarRentalVehicle $vehicle): array => $this->presenter->vehiclePayload($vehicle)),
            'period' => [
                'pickup_at' => $pickupAt->toIso8601String(),
                'due_at' => $dueAt->toIso8601String(),
                'timezone' => $company->timezone,
                'timezone_label' => $company->timezone_display_name,
            ],
        ]);
    }
}
