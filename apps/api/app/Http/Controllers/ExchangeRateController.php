<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ExchangeRateService;
use App\Support\Money;
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
            'rate_htg_per_usd' => ['required', 'numeric', 'gt:0', 'max:100000', 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
            'brh_reference_rate' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
            'brh_reference_date' => ['nullable', 'required_with:brh_reference_rate', 'date_format:Y-m-d', 'before_or_equal:today'],
            'confirm_below_brh' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'rate_htg_per_usd.required' => 'Saisissez le taux : nombre de gourdes pour 1 USD.',
            'rate_htg_per_usd.gt' => 'Le taux doit être supérieur à zéro.',
            'rate_htg_per_usd.regex' => 'Saisissez le taux avec quatre décimales au plus, par exemple 131.2500.',
            'brh_reference_rate.regex' => 'Saisissez la référence avec quatre décimales au plus, par exemple 131.2500.',
            'brh_reference_date.required_with' => 'Indiquez la date de la référence saisie.',
            'brh_reference_date.before_or_equal' => 'La date de la référence ne peut pas être dans le futur.',
        ]);

        // Taux comparés en dix-millièmes entiers, jamais en flottant.
        $rate = Money::rateUnits((string) $data['rate_htg_per_usd']);
        $brh = isset($data['brh_reference_rate']) ? Money::rateUnits((string) $data['brh_reference_rate']) : null;
        $below = $brh !== null && $rate < $brh;

        if ($below && (($data['confirm_below_brh'] ?? false) !== true || ! filled($data['note'] ?? null))) {
            throw ValidationException::withMessages([
                'confirm_below_brh' => sprintf(
                    'Ce taux est inférieur à la référence saisie (%s HTG). Confirmez-le et indiquez le motif.',
                    str_replace('.', ',', Money::fromScaled($brh, Money::RATE_SCALE)),
                ),
            ]);
        }

        $record = ExchangeRate::query()->create([
            'rate_htg_per_usd' => Money::fromScaled($rate, Money::RATE_SCALE),
            'brh_reference_rate' => $brh === null ? null : Money::fromScaled($brh, Money::RATE_SCALE),
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
