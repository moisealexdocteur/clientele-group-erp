<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Models\PartsProductFitment;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Remplace la liste des compatibilités : marque, modèle et années lorsque connus. */
final class SaveProductFitments extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly PartsPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $product): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $model = $this->lookup->productFor($company, $product);

        $data = $request->validate([
            'fitments' => ['present', 'array', 'max:50'],
            'fitments.*.make' => ['required', 'string', 'max:60'],
            'fitments.*.model' => ['nullable', 'string', 'max:80'],
            'fitments.*.year_from' => ['nullable', 'integer', 'between:1950,2100'],
            'fitments.*.year_to' => ['nullable', 'integer', 'between:1950,2100'],
        ], [
            'fitments.max' => '50 compatibilités au plus par article.',
            'fitments.*.make.required' => 'Saisissez la marque de la motocyclette.',
            'fitments.*.year_from.between' => 'Saisissez une année entre 1950 et 2100.',
            'fitments.*.year_to.between' => 'Saisissez une année entre 1950 et 2100.',
        ]);

        foreach ($data['fitments'] as $index => $fitment) {
            if (isset($fitment['year_from'], $fitment['year_to']) && $fitment['year_from'] > $fitment['year_to']) {
                throw ValidationException::withMessages([
                    "fitments.{$index}.year_to" => 'L’année de fin doit suivre l’année de début.',
                ]);
            }
        }

        DB::transaction(function () use ($company, $model, $data, $actor): void {
            PartsProductFitment::query()->where('company_id', $company->id)->where('product_id', $model->id)->delete();

            foreach ($data['fitments'] as $fitment) {
                PartsProductFitment::query()->create([
                    'company_id' => $company->id,
                    'product_id' => $model->id,
                    'make' => trim($fitment['make']),
                    'model' => Text::nullableTrimmed($fitment['model'] ?? null),
                    'year_from' => $fitment['year_from'] ?? null,
                    'year_to' => $fitment['year_to'] ?? null,
                ]);
            }

            $this->audit->record(
                eventType: 'parts.product_fitments_saved',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: PartsProduct::class,
                subjectId: $model->id,
                metadata: ['sku' => $model->sku, 'count' => count($data['fitments'])],
            );
        });

        return response()->json([
            'data' => $this->presenter->productPayload($model->unsetRelation('fitments'), $access, $this->lookup->stockSites($company, $access)),
        ]);
    }
}
