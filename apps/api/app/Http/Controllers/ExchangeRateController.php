<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ExchangeRate;
use App\Support\AuditLogger;
use App\Support\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Taux HTG/USD du groupe, réglé dans Configuration. Il est saisi par le
 * propriétaire ou par les personnes qu'il désigne ; un taux inférieur à la
 * référence BRH exige une confirmation explicite et un motif, et reste
 * signalé dans l'historique.
 */
final class ExchangeRateController extends Controller
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $history = ExchangeRate::query()
            ->with('author')
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'current' => $this->rates->payload($this->rates->current()),
            'can_manage' => $request->user() instanceof User && $request->user()->canManageExchangeRates(),
            'history' => $history->map(fn (ExchangeRate $rate): ?array => $this->rates->payload($rate))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->canManageExchangeRates()) {
            $this->audit->record(
                eventType: 'authorization.exchange_rate_refused',
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: User::class,
                subjectId: $actor?->id,
                metadata: [],
            );

            return response()->json(['message' => 'Seuls le propriétaire et les personnes désignées dans Configuration peuvent saisir le taux.'], 403);
        }

        $data = $request->validate([
            'rate_htg_per_usd' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'brh_reference_rate' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'brh_reference_date' => ['nullable', 'required_with:brh_reference_rate', 'date_format:Y-m-d', 'before_or_equal:today'],
            'confirm_below_brh' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'rate_htg_per_usd.required' => 'Saisissez le taux : nombre de gourdes pour 1 USD.',
            'rate_htg_per_usd.gt' => 'Le taux doit être supérieur à zéro.',
            'brh_reference_date.required_with' => 'Indiquez la date du taux de référence BRH.',
            'brh_reference_date.before_or_equal' => 'La date du taux BRH ne peut pas être dans le futur.',
        ]);

        $rate = (float) $data['rate_htg_per_usd'];
        $brh = isset($data['brh_reference_rate']) ? (float) $data['brh_reference_rate'] : null;
        $below = $brh !== null && $rate + 0.00001 < $brh;

        if ($below && (($data['confirm_below_brh'] ?? false) !== true || ! filled($data['note'] ?? null))) {
            throw ValidationException::withMessages([
                'confirm_below_brh' => sprintf(
                    'Ce taux est inférieur au taux de référence BRH (%s HTG). Confirmez-le et indiquez le motif.',
                    number_format($brh, 4, ',', ' '),
                ),
            ]);
        }

        $record = ExchangeRate::query()->create([
            'rate_htg_per_usd' => number_format($rate, 4, '.', ''),
            'brh_reference_rate' => $brh === null ? null : number_format($brh, 4, '.', ''),
            'brh_reference_date' => $data['brh_reference_date'] ?? null,
            'below_brh' => $below,
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
            'set_by' => $actor?->id,
            'effective_at' => now()->utc(),
        ]);

        $this->audit->record(
            eventType: $below ? 'finance.exchange_rate_set_below_brh' : 'finance.exchange_rate_set',
            companyId: null,
            actorId: $actor->id,
            actorType: 'USER',
            subjectType: ExchangeRate::class,
            subjectId: $record->id,
            metadata: [
                'rate_htg_per_usd' => $record->rate_htg_per_usd,
                'brh_reference_rate' => $record->brh_reference_rate,
                'below_brh' => $below,
            ],
        );

        return response()->json([
            'data' => $this->rates->payload($record->load('author')),
        ], 201);
    }
}
