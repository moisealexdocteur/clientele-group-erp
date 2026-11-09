<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Site;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Type d'une adresse : bureau, magasin qui vend ou entrepôt. Propriétaire
 * seulement, journalisé. Le stock déjà présent reste à l'adresse.
 */
final class UpdateSiteKind extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Company $company, string $site): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Site::KINDS)],
        ], [
            'kind.required' => 'Sélectionnez le type d’adresse.',
            'kind.in' => 'Sélectionnez Bureau, Magasin ou Entrepôt.',
        ]);

        return $this->companyContext->within($company->id, function () use ($company, $site, $data, $request): JsonResponse {
            $model = Site::query()->where('company_id', $company->id)->whereKey($site)->first();
            abort_if($model === null, 404, 'Adresse introuvable pour cette société.');

            $previous = $model->kind;
            $model->forceFill(['kind' => $data['kind']])->save();

            if ($previous !== $model->kind) {
                $this->audit->record(
                    eventType: 'configuration.site_kind_changed',
                    companyId: $company->id,
                    actorId: $request->user()?->id,
                    actorType: 'USER',
                    subjectType: Site::class,
                    subjectId: $model->id,
                    metadata: ['from' => $previous, 'to' => $model->kind],
                );
            }

            return response()->json(['data' => ['id' => $model->id, 'kind' => $model->kind]]);
        });
    }
}
