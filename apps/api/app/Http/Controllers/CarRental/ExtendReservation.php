<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Support\AuditLogger;
use App\Support\CarRentalAvailabilityService;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExtendReservation extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly CarRentalAvailabilityService $availability,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
    ) {
    }

    /**
     * Prolonge une location active sans toucher à une réservation future.
     * Un conflit est refusé avant toute écriture et ne divulgue aucun client.
     */
    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'due_at' => ['required', 'date'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $model = DB::transaction(function () use ($company, $access, $reservation, $data): CarRentalReservation {
            $model = $this->lookup->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'checked_out') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une location en circulation peut être prolongée.',
                ]);
            }

            $this->lookup->assertLockVersion($model, $data['expected_lock_version']);
            $dueAt = CarbonImmutable::parse($data['due_at'], $company->timezone)->utc();

            if ($dueAt->lessThanOrEqualTo(CarbonImmutable::instance($model->due_at))) {
                throw ValidationException::withMessages([
                    'due_at' => 'La nouvelle date de retour doit être postérieure au retour prévu.',
                ]);
            }

            $vehicle = $this->lookup->vehicleForReservation($company, $model);

            if ($vehicle->operational_status !== 'in_circulation') {
                throw ValidationException::withMessages([
                    'reservation' => 'Le véhicule doit être en circulation avant de prolonger la location.',
                ]);
            }

            try {
                $this->availability->assertVehiclePeriodAvailable(
                    $vehicle,
                    CarbonImmutable::instance($model->pickup_at),
                    $dueAt,
                    $model->id,
                    true,
                );
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'due_at' => 'La prolongation est impossible : ce véhicule a une réservation à venir. La réservation suivante n’a pas été modifiée.',
                ]);
            }

            $model->forceFill([
                'due_at' => $dueAt,
                'lock_version' => $model->lock_version + 1,
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile']);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_extended',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'site_id' => $model->site_id,
                'vehicle_id' => $model->vehicle_id,
                'billing_recalculated' => false,
            ],
        );

        $customerNotificationSent = $this->customerNotifications->notify(
            $company,
            $model,
            CarRentalCustomerNotificationService::EXTENDED,
        );

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
            'customer_notification_sent' => $customerNotificationSent,
        ]);
    }
}
