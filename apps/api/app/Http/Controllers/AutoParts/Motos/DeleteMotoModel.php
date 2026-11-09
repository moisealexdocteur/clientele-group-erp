<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoModel;
use App\Models\MotoOrder;
use App\Models\MotoUnit;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supprime un modèle saisi par erreur. Un modèle qui a déjà des motos ou des
 * commandes reste dans l'historique : il se désactive et disparaît des listes.
 */
final class DeleteMotoModel extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function __invoke(Request $request, string $model): JsonResponse
    {
        $company = $this->company($request);
        $actor = $request->user();
        $record = MotoModel::query()->where('company_id', $company->id)->whereKey($model)->first();
        abort_if($record === null, 404, 'Modèle introuvable.');

        $used = MotoUnit::query()->where('company_id', $company->id)->where('model_id', $record->id)->exists()
            || MotoOrder::query()->where('company_id', $company->id)->where('model_id', $record->id)->exists();

        if ($used) {
            return response()->json([
                'message' => 'Ce modèle a déjà des motos ou des commandes : il reste dans l’historique. Désactivez-le pour le retirer de la vente.',
                'errors' => ['model' => ['Ce modèle a déjà des motos ou des commandes. Désactivez-le.']],
            ], 422);
        }

        $label = $record->label();
        $record->delete();
        $this->audit->record(
            eventType: 'parts.moto_model_deleted',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: MotoModel::class,
            subjectId: $model,
            metadata: ['model' => $label],
        );

        return response()->json(['message' => 'Modèle supprimé.']);
    }
}
