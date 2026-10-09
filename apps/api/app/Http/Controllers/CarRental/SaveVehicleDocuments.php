<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalVehicle;
use App\Models\CarRentalVehicleDocument;
use App\Support\AuditLogger;
use App\Support\CarRental\CarRentalLookup;
use App\Support\CarRental\CarRentalPresenter;
use App\Support\CarRental\CarRentalVehicleRules;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveVehicleDocuments extends Controller
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
            'documents' => ['required', 'array', 'min:1', 'max:3'],
            'documents.*.type' => ['required', 'distinct', Rule::in(CarRentalVehicleDocument::TYPES)],
            'documents.*.document_number' => ['nullable', 'string', 'max:100'],
            'documents.*.issued_at' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.expires_at' => ['nullable', 'date_format:Y-m-d'],
        ], $this->vehicleRules->vehicleDocumentValidationMessages());

        foreach ($data['documents'] as $document) {
            if (in_array($document['type'], ['oavct_insurance', 'tint_permit'], true)
                && empty($document['expires_at'])) {
                throw ValidationException::withMessages([
                    'documents' => $document['type'] === 'oavct_insurance'
                        ? 'Indiquez la date d’expiration de l’assurance OAVCT.'
                        : 'Indiquez la date d’expiration du permis de vitres teintées.',
                ]);
            }
        }

        DB::transaction(function () use ($company, $model, $data): void {
            foreach ($data['documents'] as $document) {
                CarRentalVehicleDocument::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'vehicle_id' => $model->id,
                        'document_type' => $document['type'],
                    ],
                    [
                        'document_number' => Text::nullableTrimmed($document['document_number'] ?? null),
                        'issued_at' => $document['issued_at'] ?? null,
                        'expires_at' => $document['expires_at'] ?? null,
                    ],
                );
            }
        });

        $this->audit->record(
            eventType: 'car_rental.vehicle_documents_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CarRentalVehicle::class,
            subjectId: $model->id,
            metadata: [
                'site_id' => $model->site_id,
                'document_types' => collect($data['documents'])->pluck('type')->sort()->values()->all(),
            ],
        );

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
