<?php

namespace App\Http\Controllers\AutoParts\Customers;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\MotoOrder;
use App\Models\PartsSale;
use App\Support\AutoParts\MotoRules;
use App\Support\AutoParts\PartsCustomerBook;
use App\Support\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fiche client : coordonnées, commandes de motos et achats au comptoir. */
final class ShowPartsCustomer extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsCustomerBook $book,
        private readonly ReceiptService $receipts,
    ) {
    }

    public function __invoke(Request $request, string $customer): JsonResponse
    {
        $company = $this->company($request);
        $profile = CustomerProfile::query()->where('company_id', $company->id)->whereKey($customer)->first();
        abort_if($profile === null, 404, 'Client introuvable.');

        $orders = MotoOrder::query()->with(['motoModel', 'unit'])
            ->where('company_id', $company->id)->where('customer_profile_id', $profile->id)
            ->orderByDesc('submitted_at')->limit(50)->get();
        $sales = PartsSale::query()
            ->where('company_id', $company->id)->where('customer_profile_id', $profile->id)
            ->orderByDesc('sold_at')->limit(50)->get();

        return response()->json([
            'data' => [
                ...$this->book->payload($profile),
                'moto_orders' => $orders->map(fn (MotoOrder $order): array => [
                    'id' => $order->id,
                    'number' => $this->receipts->display($order->number),
                    'status' => $order->status,
                    'status_label' => MotoRules::STATUS_LABELS[$order->status] ?? $order->status,
                    'model' => $order->motoModel?->label(),
                    'color' => $order->unit?->color ?? $order->preferred_color,
                    'price' => (string) $order->price,
                    'price_currency' => $order->price_currency,
                    'receipt_number' => $order->receipt_number === null ? null : $this->receipts->display($order->receipt_number),
                    'submitted_at' => $order->submitted_at?->toIso8601String(),
                    'delivered_at' => $order->delivered_at?->toIso8601String(),
                ])->values(),
                'sales' => $sales->map(fn (PartsSale $sale): array => [
                    'id' => $sale->id,
                    'number' => $this->receipts->display($sale->number),
                    'total' => (string) $sale->total,
                    'currency' => $sale->currency,
                    'item_count' => (int) $sale->item_count,
                    'price_list' => $sale->price_list,
                    'sold_at' => $sale->sold_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }
}
