<?php

namespace App\Support\AutoParts;

use App\Models\Company;
use App\Models\MotoOrder;
use App\Models\MotoUnit;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Str;

/**
 * Attribution des motocyclettes disponibles aux commandes payées, dans
 * l'ordre de paiement (date du dépôt, puis heure d'enregistrement).
 * Une commande avec une couleur demandée attend une moto de cette couleur ;
 * sans couleur demandée, toute couleur convient. À l'intérieur d'un modèle,
 * une moto déjà présente à l'adresse de retrait est préférée.
 * À appeler dans une transaction.
 */
final class MotoAllocator
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return int nombre de commandes qui ont reçu une moto */
    public function allocate(Company $company, ?User $actor): int
    {
        $orders = MotoOrder::query()
            ->where('company_id', $company->id)
            ->where('status', 'paid')
            ->whereNull('unit_id')
            ->orderBy('deposit_date')
            ->orderBy('submitted_at')
            ->lockForUpdate()
            ->get();

        $assigned = 0;

        foreach ($orders as $order) {
            $unit = MotoUnit::query()
                ->where('company_id', $company->id)
                ->where('status', 'in_stock')
                ->where('model_id', $order->model_id)
                ->when($order->preferred_color !== null, static fn ($query) => $query->whereRaw('lower(color) = ?', [Str::lower($order->preferred_color)]))
                ->orderByRaw('case when site_id = ? then 0 else 1 end', [$order->site_id])
                ->orderBy('created_at')
                ->orderBy('frame_number')
                ->lockForUpdate()
                ->first();

            if ($unit === null) {
                continue;
            }

            $this->attach($company, $order, $unit, $actor, 'Attribution automatique dans l’ordre de paiement.');
            $assigned++;
        }

        return $assigned;
    }

    public function attach(Company $company, MotoOrder $order, MotoUnit $unit, ?User $actor, string $note): void
    {
        $unit->forceFill(['status' => 'reserved', 'order_id' => $order->id])->save();
        $order->forceFill([
            'status' => 'assigned',
            'unit_id' => $unit->id,
            'assigned_by' => $actor?->id,
            'assigned_at' => now()->utc(),
            'assignment_note' => $note,
        ])->save();

        $this->audit->record(
            eventType: 'parts.moto_assigned',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: MotoOrder::class,
            subjectId: $order->id,
            metadata: ['unit_id' => $unit->id, 'frame_number' => $unit->frame_number, 'note' => $note],
        );
    }

    /** Libère la moto d'une commande ; la commande revient en attente. */
    public function detach(Company $company, MotoOrder $order, ?User $actor, string $reason): void
    {
        if ($order->unit_id === null) {
            return;
        }

        MotoUnit::query()
            ->where('company_id', $company->id)
            ->whereKey($order->unit_id)
            ->update(['status' => 'in_stock', 'order_id' => null]);

        $this->audit->record(
            eventType: 'parts.moto_unassigned',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: MotoOrder::class,
            subjectId: $order->id,
            metadata: ['unit_id' => $order->unit_id, 'reason' => $reason],
        );

        $order->forceFill([
            'status' => $order->status === 'assigned' ? 'paid' : $order->status,
            'unit_id' => null,
            'assigned_by' => null,
            'assigned_at' => null,
            'assignment_note' => null,
            'called_by' => null,
            'called_at' => null,
        ])->save();
    }
}
