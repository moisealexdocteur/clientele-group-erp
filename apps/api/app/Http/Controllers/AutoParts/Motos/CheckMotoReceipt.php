<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoRules;
use App\Support\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contrôle au comptoir d'un reçu de vente présenté par un client : le QR
 * scanné, ou le numéro et le code de vérification imprimés. Le résultat
 * montre la vente enregistrée pour comparer avec le papier et la moto.
 * Chaque contrôle est journalisé, valide ou non.
 */
final class CheckMotoReceipt extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly ReceiptService $receipts,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $actor = $request->user();
        $data = $request->validate([
            'scanned' => ['nullable', 'string', 'max:500'],
            'number' => ['nullable', 'string', 'max:20'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        [$number, $signature, $code] = $this->reference($data);
        $order = $number === null ? null : MotoOrder::query()->with(['motoModel', 'unit', 'site'])
            ->where('company_id', $company->id)->where('receipt_number', $number)->first();

        $valid = false;
        if ($order !== null) {
            $expected = $this->receipts->signature($company->id, $number, (string) $order->price, $order->price_currency);
            $valid = $expected !== null && (
                ($signature !== null && hash_equals($expected, strtolower($signature)))
                || ($code !== null && hash_equals(substr($expected, 0, 8), strtolower($code)))
            );
        }

        $this->audit->record(
            eventType: $valid ? 'parts.moto_receipt_checked' : 'parts.moto_receipt_rejected',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: $order === null ? null : MotoOrder::class,
            subjectId: $order?->id,
            metadata: ['receipt_number' => $number],
        );

        if (! $valid || $order === null) {
            return response()->json([
                'valid' => false,
                'message' => $order === null
                    ? 'Aucune vente ne porte ce numéro de reçu. Ce reçu n’est pas authentique.'
                    : 'Le code ne correspond pas à ce reçu. Ce reçu a été modifié ou recopié.',
            ]);
        }

        $unit = $order->unit;

        return response()->json([
            'valid' => true,
            'order' => [
                'id' => $order->id,
                'number' => $this->receipts->display($order->number),
                'receipt_number' => $this->receipts->display($number),
                'status' => $order->status,
                'status_label' => MotoRules::STATUS_LABELS[$order->status] ?? $order->status,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'model' => $order->motoModel?->label(),
                'model_year' => $unit?->model_year,
                'color' => $unit?->color,
                'frame_number' => $unit?->frame_number,
                'engine_number' => $unit?->engine_number,
                'price' => (string) $order->price,
                'price_currency' => $order->price_currency,
                'deposit_reference' => $order->deposit_reference,
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'customs_document_pending' => $order->status === 'delivered' && ! $order->customs_document_delivered,
                'site' => $order->site?->name,
            ],
        ]);
    }

    /**
     * Numéro de reçu et preuve : signature complète du QR, ou code imprimé.
     *
     * @param array<string, mixed> $data
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function reference(array $data): array
    {
        $scanned = trim((string) ($data['scanned'] ?? ''));
        if ($scanned !== '' && preg_match('~/verification/recu/[^/]+/(\d{8})\?s=([0-9a-f]{64})~i', $scanned, $match) === 1) {
            return [$match[1], $match[2], null];
        }

        $number = preg_replace('/\D/', '', (string) ($data['number'] ?? ''));
        $code = preg_replace('/[^0-9a-fA-F]/', '', (string) ($data['code'] ?? ''));

        return [
            strlen((string) $number) === 8 ? $number : null,
            null,
            strlen((string) $code) === 8 ? $code : null,
        ];
    }
}
