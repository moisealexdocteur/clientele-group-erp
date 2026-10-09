<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CancelReservation extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'reason_code' => ['required', Rule::in([
                'customer_request',
                'vehicle_unavailable',
                'business_decision',
                'other',
            ])],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $model = DB::transaction(function () use ($company, $access, $reservation, $data): CarRentalReservation {
            $model = $this->lookup->reservationFor($company, $access, $reservation, [], true);

            if ($model->state !== 'reserved') {
                throw ValidationException::withMessages([
                    'reservation' => 'Seule une réservation non remise peut être annulée.',
                ]);
            }

            $this->lookup->assertLockVersion($model, $data['expected_lock_version']);
            $model->forceFill([
                'state' => 'cancelled',
                'lock_version' => $model->lock_version + 1,
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile']);
        });

        $this->audit->record(
            eventType: 'car_rental.reservation_cancelled',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'site_id' => $model->site_id,
                'reason_code' => $data['reason_code'],
                'financial_action_created' => false,
            ],
        );

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
        ]);
    }
}
