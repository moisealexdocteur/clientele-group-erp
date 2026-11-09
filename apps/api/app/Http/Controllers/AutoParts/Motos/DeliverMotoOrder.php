<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Models\MotoUnit;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\MotoRules;
use App\Support\AutoParts\PartsLookup;
use App\Support\DocumentNumberService;
use App\Support\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Remise de la moto au client : fiche de dépôt originale présentée, pièce
 * d'identité officielle avec photo, contrôle de conformité complet avec le
 * client, accessoires et document de dédouanement. Le reçu de vente reçoit
 * son numéro sur huit chiffres. Vente finale, sans garantie.
 */
final class DeliverMotoOrder extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $order): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'deposit_slip_presented' => ['accepted'],
            'id_document_type' => ['required', Rule::in(array_keys(MotoRules::ID_DOCUMENTS))],
            'id_document_number' => ['required', 'string', 'max:64'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'checklist' => ['required', 'array'],
            'checklist.*' => ['boolean'],
            'accessories' => ['required', 'array'],
            'accessories.*' => ['boolean'],
            'customs_document_delivered' => ['required', 'boolean'],
            'final_sale_acknowledged' => ['accepted'],
        ], [
            'deposit_slip_presented.accepted' => 'Vérifiez la fiche de dépôt originale présentée par le client.',
            'id_document_type.required' => 'Choisissez la pièce d’identité présentée.',
            'id_document_number.required' => 'Saisissez le numéro de la pièce d’identité.',
            'customs_document_delivered.required' => 'Indiquez si le document de dédouanement est remis.',
            'final_sale_acknowledged.accepted' => 'Le client doit accepter la vente finale, sans garantie.',
        ]);

        $missing = array_values(array_filter(
            array_keys(MotoRules::CHECKLIST),
            static fn (string $key): bool => ! ($data['checklist'][$key] ?? false),
        ));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'checklist' => 'Contrôle incomplet : ' . implode(', ', array_map(static fn (string $key): string => mb_strtolower(MotoRules::CHECKLIST[$key]), $missing)) . '. La moto ne peut pas être remise tant qu’un point n’est pas conforme.',
            ]);
        }

        $model = DB::transaction(function () use ($company, $access, $order, $actor, $data): MotoOrder {
            $model = $this->lookup->motoOrderFor($company, $access, $order, true);

            if ($model->status !== 'assigned' || $model->unit_id === null) {
                throw ValidationException::withMessages(['order' => 'Seule une commande avec une moto attribuée peut être remise.']);
            }

            $unit = MotoUnit::query()->where('company_id', $company->id)->whereKey($model->unit_id)->lockForUpdate()->firstOrFail();
            $customs = (bool) $data['customs_document_delivered'];

            if ($customs && ! $unit->customs_document_available) {
                throw ValidationException::withMessages([
                    'customs_document_delivered' => 'Le document de dédouanement de cette moto n’est pas encore disponible. Il sera remis plus tard.',
                ]);
            }

            $now = now()->utc();
            $unit->forceFill(['status' => 'delivered'])->save();
            $model->forceFill([
                'status' => 'delivered',
                'id_document_type' => $data['id_document_type'],
                'id_document_number' => trim($data['id_document_number']),
                'customer_address' => trim((string) ($data['customer_address'] ?? '')) ?: $model->customer_address,
                'checklist' => collect(MotoRules::CHECKLIST)->map(static fn (): bool => true)->all(),
                'accessories' => collect(MotoRules::ACCESSORIES)->map(static fn (array $item, string $key): bool => (bool) ($data['accessories'][$key] ?? false))->all(),
                'customs_document_delivered' => $customs,
                'customs_document_delivered_by' => $customs ? $actor?->id : null,
                'customs_document_delivered_at' => $customs ? $now : null,
                'receipt_number' => $this->numbers->next($company->id, ReceiptService::SEQUENCE_SCOPE),
                'delivered_by' => $actor?->id,
                'delivered_at' => $now,
            ])->save();

            $this->audit->record(
                eventType: 'parts.moto_delivered',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: MotoOrder::class,
                subjectId: $model->id,
                metadata: [
                    'receipt_number' => $this->numbers->display($model->receipt_number),
                    'frame_number' => $unit->frame_number,
                    'engine_number' => $unit->engine_number,
                    'customs_document_delivered' => $customs,
                    'accessories_missing' => array_keys(array_filter($model->accessories, static fn (bool $delivered): bool => ! $delivered)),
                ],
            );

            return $model->refresh();
        });

        return response()->json(['data' => $this->presenter->orderPayload($model, $company, $access, [], true)]);
    }
}
