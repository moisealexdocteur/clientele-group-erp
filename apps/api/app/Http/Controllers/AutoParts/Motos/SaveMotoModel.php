<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoModel;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoPresenter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Création ou modification d'un modèle et de son prix. Une commande déjà
 * enregistrée garde le prix du jour de son paiement.
 */
final class SaveMotoModel extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly MotoPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, ?string $model = null): JsonResponse
    {
        $company = $this->company($request);
        $actor = $request->user();
        $record = $model === null ? null : MotoModel::query()->where('company_id', $company->id)->whereKey($model)->first();
        abort_if($model !== null && $record === null, 404, 'Modèle introuvable.');

        $request->merge(['model' => is_string($request->input('model')) ? strtoupper(trim($request->input('model'))) : $request->input('model')]);
        $data = $request->validate([
            'make' => ['required', 'string', 'max:40'],
            'model' => [
                'required', 'string', 'max:60',
                Rule::unique('moto_models', 'model')->where(fn ($query) => $query->where('company_id', $company->id)->where('make', trim((string) $request->input('make'))))->ignore($record?->id),
            ],
            'displacement_cc' => ['nullable', 'integer', 'between:49,2000'],
            'cylinders' => ['required', 'integer', 'between:1,8'],
            'price' => ['required', new DecimalAmount()],
            'price_currency' => ['required', Rule::in(['HTG', 'USD'])],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'make.required' => 'Saisissez la marque.',
            'model.required' => 'Saisissez le modèle, par exemple HJ-125A-D.',
            'model.unique' => 'Ce modèle existe déjà.',
            'displacement_cc.between' => 'Saisissez une cylindrée entre 49 et 2000 cm³.',
            'cylinders.required' => 'Saisissez le nombre de cylindres.',
            'price.required' => 'Saisissez le prix de vente.',
            'price_currency.required' => 'Choisissez la devise du prix.',
        ]);

        $price = Money::normalize((string) $data['price']);
        if (Money::toCents($price) <= 0) {
            throw ValidationException::withMessages(['price' => 'Le prix doit être supérieur à 0.']);
        }

        $before = $record === null ? null : ['price' => (string) $record->price, 'price_currency' => $record->price_currency];
        $record ??= new MotoModel(['company_id' => $company->id]);
        $record->forceFill([
            'company_id' => $company->id,
            'make' => trim($data['make']),
            'model' => $data['model'],
            'displacement_cc' => $data['displacement_cc'] ?? null,
            'cylinders' => (int) $data['cylinders'],
            'price' => $price,
            'price_currency' => $data['price_currency'],
            'is_active' => $data['is_active'] ?? $record->is_active ?? true,
        ])->save();

        $this->audit->record(
            eventType: $before === null ? 'parts.moto_model_created' : 'parts.moto_model_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: MotoModel::class,
            subjectId: $record->id,
            metadata: ['model' => $record->label(), 'before' => $before, 'after' => ['price' => $price, 'price_currency' => $record->price_currency]],
        );

        return response()->json(['data' => $this->presenter->modelPayload($record)], $before === null ? 201 : 200);
    }
}
