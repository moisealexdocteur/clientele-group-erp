<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class NotifyReservation extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
    ) {
    }

    /** Renvoie au client la confirmation à jour de sa réservation. */
    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $model = $this->lookup->reservationFor($company, $access, $reservation, ['site', 'vehicle', 'customerProfile']);

        if (! in_array($model->state, ['reserved', 'checked_out'], true)) {
            throw ValidationException::withMessages([
                'reservation' => 'Cette réservation est terminée ou annulée : aucune confirmation n’est renvoyée.',
            ]);
        }

        if (empty($model->customerProfile?->email)) {
            throw ValidationException::withMessages([
                'customer.email' => 'Ajoutez le courriel du client avant de renvoyer la confirmation.',
            ]);
        }

        $sent = $this->customerNotifications->notify(
            $company,
            $model,
            CarRentalCustomerNotificationService::RESERVATION_UPDATED,
        );

        return response()->json([
            'customer_notification_sent' => $sent,
            'message' => $sent
                ? 'La confirmation a été envoyée au client.'
                : 'La confirmation n’a pas pu être envoyée. Vérifiez le courriel du client et le serveur de courriel.',
        ], $sent ? 200 : 502);
    }
}
