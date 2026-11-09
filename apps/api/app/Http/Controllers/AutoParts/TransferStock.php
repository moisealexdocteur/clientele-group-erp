<?php

namespace App\Http\Controllers\AutoParts;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\PartsProduct;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsLookup;
use App\Support\AutoParts\PartsPresenter;
use App\Support\AutoParts\StockLedger;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Transfert entre deux emplacements de la société (entrepôt vers magasin,
 * par exemple) : une sortie et une entrée liées par le même identifiant.
 */
final class TransferStock extends Controller
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
            'from_site_id' => ['required', 'uuid'],
            'to_site_id' => ['required', 'uuid', 'different:from_site_id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'from_site_id.required' => 'Choisissez l’emplacement de départ.',
            'to_site_id.required' => 'Choisissez l’emplacement d’arrivée.',
            'to_site_id.different' => 'Le départ et l’arrivée doivent être deux emplacements différents.',
            'quantity.required' => 'Saisissez la quantité à transférer.',
            'quantity.integer' => 'La quantité est un nombre entier de pièces.',
            'quantity.min' => 'La quantité à transférer doit être d’au moins 1.',
        ]);

        $from = $this->lookup->stockSiteFor($company, $access, $data['from_site_id'], 'from_site_id');
        $to = $this->lookup->stockSiteFor($company, $access, $data['to_site_id'], 'to_site_id');
        $quantity = (int) $data['quantity'];
        $note = Text::nullableTrimmed($data['note'] ?? null);

        $movements = DB::transaction(function () use ($company, $from, $to, $data, $quantity, $note, $actor): array {
            $product = PartsProduct::query()->where('company_id', $company->id)->whereKey($data['product_id'])->first();
            abort_if($product === null, 404, 'Article introuvable.');

            $transferId = $this->ledger->newTransferId();
            $out = $this->ledger->apply($product, $from, -$quantity, 'transfer_out', $actor, [
                'reference' => 'Vers ' . $to->name,
                'note' => $note,
                'transfer_id' => $transferId,
            ]);
            $in = $this->ledger->apply($product, $to, $quantity, 'transfer_in', $actor, [
                'reference' => 'De ' . $from->name,
                'note' => $note,
                'transfer_id' => $transferId,
            ]);

            $this->audit->record(
                eventType: 'parts.stock_transferred',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: PartsProduct::class,
                subjectId: $product->id,
                metadata: ['from_site_id' => $from->id, 'to_site_id' => $to->id, 'quantity' => $quantity, 'transfer_id' => $transferId],
            );

            return [$out, $in];
        });

        return response()->json([
            'data' => array_map(fn ($movement): array => $this->presenter->movementPayload($movement), $movements),
        ], 201);
    }
}
