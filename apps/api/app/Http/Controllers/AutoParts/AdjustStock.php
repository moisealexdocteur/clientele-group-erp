<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use App\Support\AutoParts\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajustement d'inventaire : la personne saisit la quantité comptée, le
 * journal enregistre l'écart avec un motif obligatoire.
 */
final class AdjustStock extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly PartsPresenter $presenter,
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'site_id' => ['required', 'uuid'],
            'counted_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'site_id.required' => 'Choisissez l’emplacement compté.',
            'counted_quantity.required' => 'Saisissez la quantité comptée.',
            'counted_quantity.integer' => 'La quantité est un nombre entier de pièces.',
            'counted_quantity.min' => 'La quantité comptée ne peut pas être négative.',
            'reason.required' => 'Indiquez le motif de l’ajustement : casse, perte, erreur de saisie, inventaire.',
        ]);

        $site = $this->lookup->stockSiteFor($company, $access, $data['site_id']);

        $movement = DB::transaction(function () use ($company, $site, $data, $actor) {
            $product = PartsProduct::query()->where('company_id', $company->id)->whereKey($data['product_id'])->first();
            abort_if($product === null, 404, 'Article introuvable.');

            $current = $this->ledger->quantity($product, $site);
            $delta = (int) $data['counted_quantity'] - $current;

            if ($delta === 0) {
                throw ValidationException::withMessages([
                    'counted_quantity' => sprintf('Le stock enregistré est déjà de %d. Aucun ajustement n’est nécessaire.', $current),
                ]);
            }

            $movement = $this->ledger->apply($product, $site, $delta, 'adjustment', $actor, [
                'reference' => 'Inventaire',
                'note' => trim($data['reason']),
            ], 'counted_quantity');

            $this->audit->record(
                eventType: 'parts.stock_adjusted',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: PartsProduct::class,
                subjectId: $product->id,
                metadata: ['site_id' => $site->id, 'from' => $current, 'to' => (int) $data['counted_quantity'], 'reason' => trim($data['reason'])],
            );

            return $movement;
        });

        return response()->json(['data' => $this->presenter->movementPayload($movement)], 201);
    }
}
