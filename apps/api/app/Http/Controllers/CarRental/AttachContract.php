<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\CarRentalCustomerNotificationService;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\FileVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class AttachContract extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalLookup $lookup,
        private readonly CarRentalPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly CarRentalCustomerNotificationService $customerNotifications,
        private readonly FileVault $files,
    ) {
    }

    /**
     * Rattache le contrat PDF signé, généré à partir de la copie figée.
     * Le contrat est définitif : il ne peut pas être remplacé.
     */
    public function __invoke(Request $request, string $reservation): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'file_id' => ['required', 'uuid'],
            'send_to_customer' => ['sometimes', 'boolean'],
        ]);

        $file = $this->files->find($company, $data['file_id'], StoredFile::PURPOSE_RENTAL_CONTRACT, 'file_id');

        $model = DB::transaction(function () use ($company, $access, $reservation, $file): CarRentalReservation {
            $model = $this->lookup->reservationFor($company, $access, $reservation, [], true);

            if (! in_array($model->state, ['checked_out', 'completed'], true) || $model->contract_snapshot === null) {
                throw ValidationException::withMessages([
                    'reservation' => 'Le contrat ne peut être émis qu’après la mise en circulation signée.',
                ]);
            }

            if ($model->contract_file_id !== null) {
                throw ValidationException::withMessages([
                    'reservation' => 'Le contrat signé de cette réservation est déjà émis.',
                ]);
            }

            $model->forceFill([
                'contract_file_id' => $file->id,
                'contract_issued_at' => now()->utc(),
            ])->save();

            return $model->load(['site', 'vehicle', 'customerProfile', 'payments', 'securityDeposits', 'inspections']);
        });

        $this->audit->record(
            eventType: 'car_rental.contract_issued',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalReservation::class,
            subjectId: $model->id,
            metadata: [
                'reservation_number' => $model->formattedNumber(),
                'contract_sha256' => $file->sha256,
                'terms_sha256' => $model->contract_snapshot['terms_sha256'] ?? null,
            ],
        );

        $sent = false;

        // Le contrat contient la plaque et le numéro de permis : il n'est
        // envoyé par courriel que sur demande explicite.
        if (($data['send_to_customer'] ?? false) === true) {
            $content = Storage::disk($file->disk)->get($file->path);
            $attachments = is_string($content) ? [[
                'name' => 'Contrat-' . $model->reservation_number . '.pdf',
                'content' => $content,
                'mime' => 'application/pdf',
            ]] : [];
            // Location en cours : courriel de remise avec le contrat joint. Sinon, envoi du contrat seul.
            $sent = $attachments !== [] && ($model->state === 'checked_out'
                ? $this->customerNotifications->notify($company, $model, CarRentalCustomerNotificationService::CHECKED_OUT, $attachments)
                : $this->customerNotifications->notifySignedContract($company, $model, $attachments));
        }

        return response()->json([
            'data' => $this->presenter->reservationPayload($model, $access),
            'customer_notification_sent' => $sent,
        ]);
    }
}
