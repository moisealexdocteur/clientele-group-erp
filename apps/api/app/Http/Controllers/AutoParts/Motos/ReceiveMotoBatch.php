<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoBatch;
use App\Models\MotoUnit;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoAllocator;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Réception d'un lot du fournisseur : chaque moto avec son numéro de châssis
 * et son numéro de moteur. Les motos sont aussitôt attribuées aux commandes
 * payées, dans l'ordre de paiement ; la liste des clients à appeler suit.
 */
final class ReceiveMotoBatch extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
        private readonly MotoAllocator $allocator,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $units = collect($request->input('units', []))->map(static fn ($unit) => is_array($unit) ? [
            ...$unit,
            'frame_number' => is_string($unit['frame_number'] ?? null) ? Str::upper(preg_replace('/\s+/', '', $unit['frame_number'])) : ($unit['frame_number'] ?? null),
            'engine_number' => is_string($unit['engine_number'] ?? null) ? Str::upper(preg_replace('/\s+/', '', $unit['engine_number'])) : ($unit['engine_number'] ?? null),
        ] : $unit)->all();
        $request->merge(['units' => $units]);

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'units' => ['required', 'array', 'min:1', 'max:200'],
            'units.*.model_id' => ['required', 'uuid'],
            'units.*.model_year' => ['required', 'integer', 'between:2000,2100'],
            'units.*.frame_number' => ['required', 'string', 'max:64', 'distinct'],
            'units.*.engine_number' => ['required', 'string', 'max:64', 'distinct'],
            'units.*.color' => ['required', 'string', 'max:60'],
            'units.*.customs_document_available' => ['required', 'boolean'],
        ], [
            'site_id.required' => 'Choisissez l’adresse qui reçoit le lot.',
            'reference.required' => 'Saisissez la référence du bon ou de la facture du fournisseur.',
            'units.required' => 'Ajoutez au moins une moto.',
            'units.*.model_year.required' => 'Saisissez l’année.',
            'units.*.frame_number.required' => 'Saisissez le numéro de châssis.',
            'units.*.frame_number.distinct' => 'Ce numéro de châssis apparaît deux fois.',
            'units.*.engine_number.required' => 'Saisissez le numéro de moteur.',
            'units.*.engine_number.distinct' => 'Ce numéro de moteur apparaît deux fois.',
            'units.*.color.required' => 'Saisissez la couleur.',
        ]);

        $site = $this->lookup->stockSiteFor($company, $access, $data['site_id']);

        $result = DB::transaction(function () use ($company, $site, $data, $actor): array {
            $batch = MotoBatch::query()->create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'reference' => trim($data['reference']),
                'note' => Text::nullableTrimmed($data['note'] ?? null),
                'received_by' => $actor?->id,
                'received_at' => now()->utc(),
            ]);

            foreach ($data['units'] as $index => $line) {
                $model = $this->lookup->motoModelFor($company, $line['model_id'], "units.{$index}.model_id");

                foreach (['frame_number', 'engine_number'] as $field) {
                    if (MotoUnit::query()->where('company_id', $company->id)->where($field, $line[$field])->exists()) {
                        throw ValidationException::withMessages([
                            "units.{$index}.{$field}" => sprintf('Le numéro %s est déjà enregistré pour une autre moto.', $line[$field]),
                        ]);
                    }
                }

                MotoUnit::query()->create([
                    'company_id' => $company->id,
                    'batch_id' => $batch->id,
                    'site_id' => $site->id,
                    'model_id' => $model->id,
                    'model_year' => (int) $line['model_year'],
                    'frame_number' => $line['frame_number'],
                    'engine_number' => $line['engine_number'],
                    'color' => trim($line['color']),
                    'cylinders' => $model->cylinders,
                    'displacement_cc' => $model->displacement_cc,
                    'customs_document_available' => (bool) $line['customs_document_available'],
                    'status' => 'in_stock',
                ]);
            }

            $this->audit->record(
                eventType: 'parts.moto_batch_received',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: MotoBatch::class,
                subjectId: $batch->id,
                metadata: ['reference' => $batch->reference, 'site_id' => $site->id, 'units' => count($data['units'])],
            );

            return ['batch' => $batch, 'assigned' => $this->allocator->allocate($company, $actor)];
        });

        return response()->json([
            'data' => [
                'id' => $result['batch']->id,
                'reference' => $result['batch']->reference,
                'units' => $result['batch']->units()->with(['motoModel', 'site'])->orderBy('frame_number')->get()->map(fn (MotoUnit $unit): array => $this->presenter->unitPayload($unit))->values(),
                'assigned' => $result['assigned'],
            ],
        ], 201);
    }
}
