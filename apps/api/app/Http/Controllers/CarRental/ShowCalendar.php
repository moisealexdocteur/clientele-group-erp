<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Models\CarRentalVehicle;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalSchedule;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowCalendar extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalSchedule $schedule,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'site_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from'],
        ]);

        [$from, $to] = isset($data['from'], $data['to'])
            ? $this->schedule->calendarInterval($company, $data['from'], $data['to'])
            : $this->schedule->defaultCalendarInterval($company);
        $siteIds = ($data['site_id'] ?? null) === null
            ? $this->siteAuthorizer->activeSiteIdsFor($company, $access)
            : collect([$this->siteAuthorizer->siteFor($company, $access, $data['site_id'])->id]);

        $reservations = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->whereIn('state', CarRentalReservation::ACTIVE_STATES)
            ->where('pickup_at', '<', $to)
            ->where('due_at', '>', $from)
            ->with(['site', 'vehicle'])
            ->orderBy('pickup_at')
            ->get();

        $vehicles = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->with('site')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $reservations->map(fn (CarRentalReservation $reservation): array => $this->presenter->calendarPayload($reservation)),
            'vehicles' => $vehicles->map(fn (CarRentalVehicle $vehicle): array => $this->presenter->vehiclePayload($vehicle)),
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'timezone' => $company->timezone,
                'timezone_label' => $company->timezone_display_name,
            ],
        ]);
    }
}
