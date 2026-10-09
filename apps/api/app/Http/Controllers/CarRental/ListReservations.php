<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Support\CarRental\CarRentalCustomerDirectory;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalSchedule;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ListReservations extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalCustomerDirectory $customers,
        private readonly CarRentalPresenter $presenter,
        private readonly CarRentalSchedule $schedule,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    /**
     * Liste opérationnelle limitée au périmètre autorisé. La recherche ne
     * porte pas sur les coordonnées des clients et la réponse ne les expose
     * jamais.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'site_id' => ['nullable', 'uuid'],
            'state' => ['nullable', Rule::in(CarRentalReservation::STATES)],
            'query' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from'],
        ]);

        [$from, $to] = isset($data['from'], $data['to'])
            ? $this->schedule->calendarInterval($company, $data['from'], $data['to'])
            : $this->schedule->defaultCalendarInterval($company);
        $siteIds = ($data['site_id'] ?? null) === null
            ? $this->siteAuthorizer->activeSiteIdsFor($company, $access)
            : collect([$this->siteAuthorizer->siteFor($company, $access, $data['site_id'])->id]);
        $search = $this->customers->reservationSearchTerm($data['query'] ?? null);

        $reservations = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->when($data['state'] ?? null, static fn ($query, string $state) => $query->where('state', $state))
            ->where('pickup_at', '<', $to)
            ->where('due_at', '>', $from)
            ->when($search !== null, function ($query) use ($search): void {
                $like = '%' . $search . '%';

                $query->where(function ($searchQuery) use ($like): void {
                    $searchQuery
                        ->whereRaw('UPPER(reservation_number) LIKE ?', [$like])
                        ->orWhereHas('vehicle', static function ($vehicleQuery) use ($like): void {
                            $vehicleQuery->whereRaw('UPPER(code) LIKE ?', [$like]);
                        });
                });
            })
            ->with(['site', 'vehicle', 'customerProfile'])
            ->orderByDesc('pickup_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $reservations
                ->map(fn (CarRentalReservation $reservation): array => $this->presenter->reservationListPayload($reservation))
                ->values(),
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'timezone' => $company->timezone,
                'timezone_label' => $company->timezone_display_name,
            ],
        ]);
    }
}
