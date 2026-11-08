<?php

namespace App\Support\AutoParts;

use App\Models\Company;
use App\Models\PartsSale;
use App\Models\PartsSaleLine;
use App\Support\ReceiptService;

/**
 * Vente au comptoir et son reçu 80 mm. La liste n'affiche jamais le nom du
 * client ; le reçu le reprend seulement s'il a été saisi pour la vente.
 */
final class SalePresenter
{
    public const PRICE_LIST_LABELS = [
        'retail' => 'Détail',
        'wholesale' => 'Gros',
    ];

    public function __construct(
        private readonly ReceiptService $receipts,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(PartsSale $sale): array
    {
        $sale->loadMissing(['site', 'cashRegister', 'seller']);

        return [
            'id' => $sale->id,
            'number' => $this->receipts->display($sale->number),
            'status' => $sale->status,
            'price_list' => $sale->price_list,
            'price_list_label' => self::PRICE_LIST_LABELS[$sale->price_list] ?? $sale->price_list,
            'site' => ['id' => $sale->site_id, 'name' => $sale->site?->name],
            'cash_register' => ['id' => $sale->cash_register_id, 'name' => $sale->cashRegister?->name],
            'seller' => $sale->seller?->name,
            'sold_at' => $sale->sold_at?->toIso8601String(),
            'item_count' => $sale->item_count,
            'currency' => $sale->currency,
            'total' => (string) $sale->total,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(PartsSale $sale, Company $company): array
    {
        $sale->loadMissing(['lines.stockSite']);

        return [
            ...$this->summary($sale),
            'customer_name' => $sale->customer_name,
            'amount_received' => (string) $sale->amount_received,
            'change_given' => (string) $sale->change_given,
            'cash_session_id' => $sale->cash_session_id,
            'print_count' => $sale->receipt_print_count,
            'site_address' => $sale->site?->address,
            'verification_url' => $this->receipts->verificationUrlFor($company, $sale->number, (string) $sale->total, $sale->currency),
            'company' => [
                'display_name' => $company->display_name,
                'name' => $company->legal_name,
                'address' => $company->legal_address,
                'phone_numbers' => $company->phone_numbers,
                'tax_identification_number' => $company->tax_identification_number,
            ],
            'lines' => $sale->lines->map(static fn (PartsSaleLine $line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'sku' => $line->sku,
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'line_total' => (string) $line->line_total,
                'stock_site' => ['id' => $line->stock_site_id, 'name' => $line->stockSite?->name],
            ])->values(),
        ];
    }
}
