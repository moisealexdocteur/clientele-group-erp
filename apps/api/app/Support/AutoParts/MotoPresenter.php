<?php

namespace App\Support\AutoParts;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\MotoModel;
use App\Models\MotoOrder;
use App\Models\MotoUnit;
use App\Support\DocumentNumberService;
use App\Support\ReceiptService;
use Illuminate\Support\Collection;

/**
 * Présentation de la vente de motocyclettes. Le téléphone du client sert à
 * l'appeler ; la pièce d'identité et la fiche de dépôt n'apparaissent que
 * dans le détail, pour les rôles qui vérifient ou remettent la moto.
 */
final class MotoPresenter
{
    public function __construct(
        private readonly ReceiptService $receipts,
        private readonly DocumentNumberService $numbers,
    ) {
    }

    /** @return array<string, mixed> */
    public function modelPayload(MotoModel $model): array
    {
        return [
            'id' => $model->id,
            'make' => $model->make,
            'model' => $model->model,
            'label' => $model->label(),
            'displacement_cc' => $model->displacement_cc,
            'cylinders' => $model->cylinders,
            'price' => (string) $model->price,
            'price_currency' => $model->price_currency,
            'is_active' => $model->is_active,
            'photo_url' => $model->photo_file_id === null ? null : '/api/v1/car-rental/files/' . $model->photo_file_id,
        ];
    }

    /** @return array<string, mixed> */
    public function unitPayload(MotoUnit $unit): array
    {
        $unit->loadMissing(['motoModel', 'site']);

        return [
            'id' => $unit->id,
            'status' => $unit->status,
            'status_label' => MotoRules::UNIT_STATUS_LABELS[$unit->status] ?? $unit->status,
            'model' => $unit->motoModel === null ? null : $this->modelPayload($unit->motoModel),
            'model_year' => $unit->model_year,
            'frame_number' => $unit->frame_number,
            'engine_number' => $unit->engine_number,
            'color' => $unit->color,
            'cylinders' => $unit->cylinders,
            'displacement_cc' => $unit->displacement_cc,
            'customs_document_available' => $unit->customs_document_available,
            'site' => ['id' => $unit->site_id, 'name' => $unit->site?->name],
            'order_id' => $unit->order_id,
            'batch_id' => $unit->batch_id,
        ];
    }

    /**
     * Rang dans la liste d'attente de chaque commande payée sans moto, par modèle.
     *
     * @return array<string, int>
     */
    public function queuePositions(Company $company): array
    {
        $positions = [];
        $counters = [];

        MotoOrder::query()
            ->where('company_id', $company->id)
            ->where('status', 'paid')
            ->whereNull('unit_id')
            ->orderBy('deposit_date')
            ->orderBy('submitted_at')
            ->get(['id', 'model_id'])
            ->each(static function (MotoOrder $order) use (&$positions, &$counters): void {
                $counters[$order->model_id] = ($counters[$order->model_id] ?? 0) + 1;
                $positions[$order->id] = $counters[$order->model_id];
            });

        return $positions;
    }

    /**
     * @param  array<string, int>  $positions
     * @return array<string, mixed>
     */
    public function orderPayload(MotoOrder $order, Company $company, CompanyUserAccess $access, array $positions = [], bool $detail = false): array
    {
        $order->loadMissing(['motoModel', 'unit.motoModel', 'unit.site', 'site']);

        $payload = [
            'id' => $order->id,
            'number' => $this->numbers->display($order->number),
            'status' => $order->status,
            'status_label' => MotoRules::STATUS_LABELS[$order->status] ?? $order->status,
            'site' => ['id' => $order->site_id, 'name' => $order->site?->name],
            'model' => $order->motoModel === null ? null : $this->modelPayload($order->motoModel),
            'preferred_color' => $order->preferred_color,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'price' => (string) $order->price,
            'price_currency' => $order->price_currency,
            'deposit_date' => $order->deposit_date?->format('Y-m-d'),
            'submitted_at' => $order->submitted_at?->toIso8601String(),
            'queue_position' => $positions[$order->id] ?? null,
            'unit' => $order->unit === null ? null : $this->unitPayload($order->unit),
            'called_at' => $order->called_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'customs_document_pending' => $order->status === 'delivered' && $order->customs_document_delivered === false,
        ];

        if (! $detail) {
            return $payload;
        }

        $sensitive = $access->allows('parts.motos.payments.verify') || $access->allows('parts.motos.deliver');

        return [
            ...$payload,
            'customer_email' => $order->customer_email,
            'customer_address' => $order->customer_address,
            'payment_amount' => (string) $order->payment_amount,
            'payment_currency' => $order->payment_currency,
            'exchange_rate_htg_per_usd' => $order->exchange_rate_htg_per_usd === null ? null : (string) $order->exchange_rate_htg_per_usd,
            'amount_in_price_currency' => (string) $order->amount_in_price_currency,
            'bank_name' => $order->bank_name,
            'deposit_reference' => $order->deposit_reference,
            'deposit_proof_url' => $sensitive && $order->deposit_proof_file_id !== null ? '/api/v1/car-rental/files/' . $order->deposit_proof_file_id : null,
            'verified_at' => $order->verified_at?->toIso8601String(),
            'assigned_at' => $order->assigned_at?->toIso8601String(),
            'assignment_note' => $order->assignment_note,
            'id_document_type' => $sensitive ? $order->id_document_type : null,
            'id_document_label' => $sensitive && $order->id_document_type !== null ? (MotoRules::ID_DOCUMENTS[$order->id_document_type] ?? $order->id_document_type) : null,
            'id_document_number' => $sensitive ? $order->id_document_number : null,
            'checklist' => $this->labelled($order->checklist, MotoRules::CHECKLIST),
            'accessories' => collect(MotoRules::ACCESSORIES)
                ->map(static fn (array $item, string $key): array => [
                    'key' => $key,
                    'label' => $item['label'],
                    'quantity' => $item['quantity'],
                    'delivered' => $order->accessories === null ? null : (bool) ($order->accessories[$key] ?? false),
                ])
                ->values(),
            'customs_document_delivered' => $order->customs_document_delivered,
            'customs_document_delivered_at' => $order->customs_document_delivered_at?->toIso8601String(),
            'receipt_number' => $order->receipt_number === null ? null : $this->receipts->display($order->receipt_number),
            'receipt_print_count' => $order->receipt_print_count,
            'certificate_print_count' => $order->certificate_print_count,
            'verification_url' => $order->receipt_number === null
                ? null
                : $this->receipts->verificationUrlFor($company, $order->receipt_number, (string) $order->price, $order->price_currency),
            'cancellation_reason' => $order->cancellation_reason,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'company' => [
                'display_name' => $company->display_name,
                'name' => $company->legal_name,
                'address' => $company->legal_address,
                'phone_numbers' => $company->phone_numbers,
                'tax_identification_number' => $company->tax_identification_number,
            ],
        ];
    }

    /**
     * @param  array<string, bool>|null  $values
     * @param  array<string, string>  $labels
     * @return Collection<int, array{key: string, label: string, ok: bool|null}>
     */
    private function labelled(?array $values, array $labels): Collection
    {
        return collect($labels)
            ->map(static fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'ok' => $values === null ? null : (bool) ($values[$key] ?? false),
            ])
            ->values();
    }
}
