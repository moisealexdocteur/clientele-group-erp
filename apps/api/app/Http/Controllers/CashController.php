<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CashSessionService;
use App\Support\CompanySiteAuthorizer;
use App\Support\ReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de caisse : ouverture avec fond USD et HTG, suivi des espèces,
 * clôture avec montants déclarés, écart expliqué puis approuvé, rapport de
 * fermeture et rapport journalier. Aucun nom ni coordonnée de client.
 */
final class CashController extends Controller
{
    private const MOVEMENT_LABELS = [
        'rental_payment' => 'Paiement de location',
        'deposit_payment' => 'Dépôt de garantie reçu',
        'deposit_refund' => 'Dépôt de garantie rendu',
    ];

    public function __construct(
        private readonly CashSessionService $cash,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
        private readonly ReceiptService $receipts,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Caisses des adresses autorisées, avec la session ouverte s'il y en a une. */
    public function registers(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $this->assertCanSeeCash($access);

        $siteIds = $this->siteAuthorizer->activeSiteIdsFor($company, $access);
        $registers = CashRegister::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->with('site')
            ->orderBy('name')
            ->get();

        $open = CashSession::query()
            ->where('company_id', $company->id)
            ->whereIn('cash_register_id', $registers->pluck('id'))
            ->where('status', 'open')
            ->with(['site', 'cashRegister', 'opener'])
            ->get()
            ->keyBy('cash_register_id');

        return response()->json([
            'data' => $registers->map(function (CashRegister $register) use ($company, $open, $access, $request): array {
                $session = $open->get($register->id);
                $last = $session === null ? $this->lastClosedSession($company, $register->id) : null;

                return [
                    'id' => $register->id,
                    'code' => $register->code,
                    'name' => $register->name,
                    'site' => ['id' => $register->site?->id, 'name' => $register->site?->name],
                    'session' => $session === null ? null : $this->sessionPayload($session, $access, $request->user()),
                    // Fond proposé à l'ouverture : les montants déclarés à la dernière clôture.
                    'suggested_opening' => [
                        'USD' => $last?->declared_usd ?? '0.00',
                        'HTG' => $last?->declared_htg ?? '0.00',
                    ],
                ];
            })->values(),
            'permissions' => [
                'operate' => $access->allows('cash.sessions.operate'),
                'approve' => $access->allows('cash.sessions.approve'),
                'reports' => $access->allows('cash.reports.read'),
            ],
        ]);
    }

    public function open(Request $request, string $register): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'opening_usd' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'opening_htg' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ], [
            'opening_usd.required' => 'Indiquez le fond de caisse en USD (0 si aucun).',
            'opening_htg.required' => 'Indiquez le fond de caisse en HTG (0 si aucun).',
        ]);

        $model = CashRegister::query()
            ->where('company_id', $company->id)
            ->whereKey($register)
            ->where('is_active', true)
            ->first();

        abort_if($model === null, 404, 'Caisse active introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        try {
            $session = DB::transaction(function () use ($company, $model, $data, $actor): CashSession {
                if ($this->cash->openSessionFor($company, $model->id, true) !== null) {
                    throw ValidationException::withMessages([
                        'register' => 'Cette caisse est déjà ouverte.',
                    ]);
                }

                return CashSession::query()->create([
                    'company_id' => $company->id,
                    'site_id' => $model->site_id,
                    'cash_register_id' => $model->id,
                    'status' => 'open',
                    'opened_by' => $actor?->id,
                    'opened_at' => now()->utc(),
                    'opening_usd' => $this->cash->money((float) $data['opening_usd']),
                    'opening_htg' => $this->cash->money((float) $data['opening_htg']),
                ]);
            });
        } catch (QueryException) {
            // Deux ouvertures simultanées : l'index unique garde une seule session.
            throw ValidationException::withMessages([
                'register' => 'Cette caisse est déjà ouverte.',
            ]);
        }

        $this->audit->record(
            eventType: 'cash.session_opened',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $session->id,
            metadata: [
                'cash_register' => $model->code,
                'opening_usd' => $session->opening_usd,
                'opening_htg' => $session->opening_htg,
            ],
        );

        return response()->json([
            'data' => $this->sessionPayload($session->load(['site', 'cashRegister', 'opener']), $access, $actor, true),
        ], 201);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $this->assertCanSeeCash($access);
        $model = $this->sessionFor($company, $access, $session);

        return response()->json([
            'data' => $this->sessionPayload($model, $access, $request->user(), true),
        ]);
    }

    public function close(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'declared_usd' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'declared_htg' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'variance_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'declared_usd.required' => 'Indiquez les espèces USD comptées (0 si aucune).',
            'declared_htg.required' => 'Indiquez les espèces HTG comptées (0 si aucune).',
        ]);

        $model = DB::transaction(function () use ($company, $access, $session, $data, $actor): CashSession {
            $model = $this->sessionFor($company, $access, $session, true);

            if ($model->status !== 'open') {
                throw ValidationException::withMessages(['session' => 'Cette session de caisse est déjà clôturée.']);
            }

            if (! $this->canClose($model, $access, $actor)) {
                abort(403, 'Seule la personne qui a ouvert la caisse ou un superviseur peut la clôturer.');
            }

            $pending = $this->pendingCashPayments($model);

            if ($pending > 0) {
                throw ValidationException::withMessages([
                    'session' => sprintf(
                        '%d paiement%s en espèces attend%s une approbation. Faites-le approuver ou rejeter avant la clôture.',
                        $pending,
                        $pending > 1 ? 's' : '',
                        $pending > 1 ? 'ent' : '',
                    ),
                ]);
            }

            $totals = $this->cash->totals($model);
            $declaredUsd = round((float) $data['declared_usd'], 2);
            $declaredHtg = round((float) $data['declared_htg'], 2);
            $varianceUsd = round($declaredUsd - (float) $totals['USD']['expected'], 2);
            $varianceHtg = round($declaredHtg - (float) $totals['HTG']['expected'], 2);
            $hasVariance = abs($varianceUsd) >= 0.005 || abs($varianceHtg) >= 0.005;
            $note = trim((string) ($data['variance_note'] ?? ''));

            if ($hasVariance && $note === '') {
                throw ValidationException::withMessages([
                    'variance_note' => 'Le montant compté diffère du montant attendu. Expliquez l’écart.',
                ]);
            }

            $model->forceFill([
                'status' => 'closed',
                'closed_by' => $actor?->id,
                'closed_at' => now()->utc(),
                'expected_usd' => $totals['USD']['expected'],
                'expected_htg' => $totals['HTG']['expected'],
                'declared_usd' => $this->cash->money($declaredUsd),
                'declared_htg' => $this->cash->money($declaredHtg),
                'variance_usd' => $this->cash->money($varianceUsd),
                'variance_htg' => $this->cash->money($varianceHtg),
                'variance_note' => $hasVariance ? $note : null,
                'review_status' => $hasVariance ? 'pending' : 'none',
            ])->save();

            return $model;
        });

        $this->audit->record(
            eventType: $model->review_status === 'pending' ? 'cash.session_closed_with_variance' : 'cash.session_closed',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $model->id,
            metadata: [
                'cash_register' => $model->cashRegister?->code,
                'expected_usd' => $model->expected_usd,
                'expected_htg' => $model->expected_htg,
                'declared_usd' => $model->declared_usd,
                'declared_htg' => $model->declared_htg,
                'variance_usd' => $model->variance_usd,
                'variance_htg' => $model->variance_htg,
            ],
        );

        return response()->json([
            'data' => $this->sessionPayload($model->fresh(['site', 'cashRegister', 'opener', 'closer', 'reviewer']), $access, $actor, true),
        ]);
    }

    /** Approbation d'un écart de clôture par un superviseur. */
    public function review(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => 'Indiquez la décision ou la mesure prise.',
        ]);

        $model = DB::transaction(function () use ($company, $access, $session, $data, $actor): CashSession {
            $model = $this->sessionFor($company, $access, $session, true);

            if ($model->review_status !== 'pending') {
                throw ValidationException::withMessages(['session' => 'Aucun écart à approuver pour cette session.']);
            }

            $model->forceFill([
                'review_status' => 'approved',
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now()->utc(),
                'review_note' => trim((string) $data['note']),
            ])->save();

            return $model;
        });

        $this->audit->record(
            eventType: 'cash.session_variance_approved',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $model->id,
            metadata: [
                'variance_usd' => $model->variance_usd,
                'variance_htg' => $model->variance_htg,
                'self_review' => $model->closed_by !== null && $model->closed_by === $actor?->id,
            ],
        );

        return response()->json([
            'data' => $this->sessionPayload($model->fresh(['site', 'cashRegister', 'opener', 'closer', 'reviewer']), $access, $actor, true),
        ]);
    }

    /** Journalise l'impression du rapport de fermeture ; la suivante est une réimpression. */
    public function recordSessionPrint(Request $request, string $session): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();
        $this->assertCanSeeCash($access);

        $model = $this->sessionFor($company, $access, $session);
        $reprint = $model->report_print_count > 0;
        $model->forceFill(['report_print_count' => $model->report_print_count + 1])->save();

        $this->audit->record(
            eventType: $reprint ? 'cash.session_report_reprinted' : 'cash.session_report_printed',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CashSession::class,
            subjectId: $model->id,
            metadata: ['print_count' => $model->report_print_count],
        );

        return response()->json([
            'data' => $this->sessionPayload($model, $access, $actor, true),
        ]);
    }

    /**
     * Rapport journalier : sessions ouvertes le jour choisi (heure de
     * Cap-Haïtien) dans les adresses autorisées, espèces par devise, écarts,
     * autres règlements approuvés et réimpressions de reçus.
     */
    public function dailyReport(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'site_id' => ['nullable', 'uuid'],
        ]);

        [$day, $start, $end] = $this->day($company, $data['date'] ?? null);
        $siteIds = $this->siteIds($company, $access, $data['site_id'] ?? null);
        $siteName = filled($data['site_id'] ?? null)
            ? $this->siteAuthorizer->siteFor($company, $access, (string) $data['site_id'])->name
            : null;

        $sessions = CashSession::query()
            ->where('company_id', $company->id)
            ->whereIn('site_id', $siteIds)
            ->where('opened_at', '>=', $start->utc())
            ->where('opened_at', '<', $end->utc())
            ->with(['site', 'cashRegister', 'opener', 'closer', 'reviewer'])
            ->orderBy('opened_at')
            ->get();

        $sessionPayloads = $sessions->map(fn (CashSession $session): array => $this->sessionPayload($session, $access, $request->user(), true))->values();

        $totals = [];
        foreach (CashSessionService::CURRENCIES as $currency) {
            $key = strtolower($currency);
            $closed = $sessions->where('status', 'closed');
            $totals[$currency] = [
                'opening' => $this->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['opening'])),
                'in' => $this->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['in'])),
                'out' => $this->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['out'])),
                'expected' => $this->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['expected'])),
                'declared' => $this->sum($closed->map(static fn (CashSession $session): string => (string) $session->{"declared_{$key}"})),
                'variance' => $this->sum($closed->map(static fn (CashSession $session): string => (string) $session->{"variance_{$key}"})),
            ];
        }

        $byKind = CashMovement::query()
            ->where('company_id', $company->id)
            ->whereIn('cash_session_id', $sessions->pluck('id'))
            ->get(['kind', 'direction', 'currency', 'amount'])
            ->groupBy(static fn (CashMovement $movement): string => $movement->kind . '|' . $movement->currency)
            ->map(fn (Collection $group, string $key): array => [
                'kind' => explode('|', $key)[0],
                'label' => self::MOVEMENT_LABELS[explode('|', $key)[0]] ?? explode('|', $key)[0],
                'direction' => $group->first()->direction,
                'currency' => explode('|', $key)[1],
                'count' => $group->count(),
                'amount' => $this->sum($group->map(static fn (CashMovement $movement): string => (string) $movement->amount)),
            ])
            ->sortBy(static fn (array $row): string => $row['kind'] . $row['currency'])
            ->values();

        $otherPayments = CarRentalPayment::query()
            ->where('company_id', $company->id)
            ->where('status', 'approved')
            ->where('method', '!=', 'cash')
            ->where('approved_at', '>=', $start->utc())
            ->where('approved_at', '<', $end->utc())
            ->whereIn('reservation_id', CarRentalReservation::query()
                ->where('company_id', $company->id)
                ->whereIn('site_id', $siteIds)
                ->select('id'))
            ->get(['method', 'currency', 'amount'])
            ->groupBy(static fn (CarRentalPayment $payment): string => $payment->method . '|' . $payment->currency)
            ->map(fn (Collection $group, string $key): array => [
                'method' => explode('|', $key)[0],
                'currency' => explode('|', $key)[1],
                'count' => $group->count(),
                'amount' => $this->sum($group->map(static fn (CarRentalPayment $payment): string => (string) $payment->amount)),
            ])
            ->sortBy(static fn (array $row): string => $row['method'] . $row['currency'])
            ->values();

        $reprints = AuditEvent::query()
            ->where('company_id', $company->id)
            ->whereIn('event_type', ['receipt.reprinted', 'cash.session_report_reprinted'])
            ->where('occurred_at', '>=', $start->utc())
            ->where('occurred_at', '<', $end->utc())
            ->count();

        return response()->json([
            'data' => [
                'date' => $day,
                'company' => [
                    'name' => $company->legal_name,
                    'display_name' => $company->display_name,
                    'tax_identification_number' => $company->tax_identification_number,
                ],
                'site' => $siteName,
                'generated_at' => now()->toIso8601String(),
                'generated_by' => $request->user()?->name,
                'sessions' => $sessionPayloads,
                'totals' => $totals,
                'movements_by_kind' => $byKind,
                'other_payments' => $otherPayments,
                'open_sessions' => $sessions->where('status', 'open')->count(),
                'pending_reviews' => $sessions->where('review_status', 'pending')->count(),
                'reprints' => $reprints,
            ],
        ]);
    }

    /** Journalise une impression ou un export du rapport journalier. */
    public function recordDailyReportExport(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'site_id' => ['nullable', 'uuid'],
            'format' => ['required', 'in:pdf,xlsx,print_a4,print_80mm'],
        ]);

        if (filled($data['site_id'] ?? null)) {
            $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        }

        $this->audit->record(
            eventType: str_starts_with($data['format'], 'print') ? 'report.daily_cash_printed' : 'report.daily_cash_exported',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            metadata: [
                'date' => $data['date'],
                'site_id' => $data['site_id'] ?? null,
                'format' => $data['format'],
            ],
        );

        return response()->json(['recorded' => true]);
    }

    /** @return array<string, mixed> */
    private function sessionPayload(CashSession $session, CompanyUserAccess $access, mixed $actor, bool $withMovements = false): array
    {
        $session->loadMissing(['site', 'cashRegister', 'opener', 'closer', 'reviewer']);
        $totals = $this->cash->totals($session);
        $user = $actor instanceof User ? $actor : null;

        $payload = [
            'id' => $session->id,
            'status' => $session->status,
            'site' => ['id' => $session->site_id, 'name' => $session->site?->name],
            'cash_register' => ['id' => $session->cash_register_id, 'code' => $session->cashRegister?->code, 'name' => $session->cashRegister?->name],
            'opened_at' => $session->opened_at?->toIso8601String(),
            'opened_by' => $session->opener?->name,
            'closed_at' => $session->closed_at?->toIso8601String(),
            'closed_by' => $session->closer?->name,
            'totals' => $totals,
            'declared' => $session->status === 'closed' ? ['USD' => $session->declared_usd, 'HTG' => $session->declared_htg] : null,
            'variance' => $session->status === 'closed' ? ['USD' => $session->variance_usd, 'HTG' => $session->variance_htg] : null,
            'variance_note' => $session->variance_note,
            'review_status' => $session->review_status,
            'reviewed_at' => $session->reviewed_at?->toIso8601String(),
            'reviewed_by' => $session->reviewer?->name,
            'review_note' => $session->review_note,
            'report_print_count' => $session->report_print_count,
            'pending_cash_payments' => $session->status === 'open' ? $this->pendingCashPayments($session) : 0,
            'can_close' => $session->status === 'open' && $this->canClose($session, $access, $user),
            'can_review' => $session->review_status === 'pending' && $access->allows('cash.sessions.approve'),
        ];

        if ($withMovements) {
            $payload['movements'] = CashMovement::query()
                ->where('company_id', $session->company_id)
                ->where('cash_session_id', $session->id)
                ->with(['payment', 'reservation', 'recorder'])
                ->orderBy('occurred_at')
                ->get()
                ->map(fn (CashMovement $movement): array => [
                    'id' => $movement->id,
                    'kind' => $movement->kind,
                    'label' => self::MOVEMENT_LABELS[$movement->kind] ?? $movement->kind,
                    'direction' => $movement->direction,
                    'currency' => $movement->currency,
                    'amount' => (string) $movement->amount,
                    'occurred_at' => $movement->occurred_at?->toIso8601String(),
                    'receipt_number' => $movement->payment?->receipt_number === null ? null : $this->receipts->display((string) $movement->payment->receipt_number),
                    'payment_id' => $movement->payment_id,
                    'reservation_id' => $movement->reservation_id,
                    'reservation_number' => $movement->reservation?->formattedNumber(),
                    'recorded_by' => $movement->recorder?->name,
                ])
                ->values();
        }

        return $payload;
    }

    private function canClose(CashSession $session, CompanyUserAccess $access, ?User $actor): bool
    {
        if (! $access->allows('cash.sessions.operate') && ! $access->allows('cash.sessions.approve')) {
            return false;
        }

        return $access->allows('cash.sessions.approve')
            || ($actor !== null && $session->opened_by === $actor->id);
    }

    private function pendingCashPayments(CashSession $session): int
    {
        return CarRentalPayment::query()
            ->where('company_id', $session->company_id)
            ->where('cash_session_id', $session->id)
            ->where('status', 'submitted')
            ->count();
    }

    private function lastClosedSession(Company $company, string $registerId): ?CashSession
    {
        return CashSession::query()
            ->where('company_id', $company->id)
            ->where('cash_register_id', $registerId)
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->first();
    }

    private function sessionFor(Company $company, CompanyUserAccess $access, string $session, bool $lock = false): CashSession
    {
        $model = CashSession::query()
            ->where('company_id', $company->id)
            ->whereKey($session)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();

        abort_if($model === null, 404, 'Session de caisse introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        return $model;
    }

    /** @return Collection<int, string> */
    private function siteIds(Company $company, CompanyUserAccess $access, ?string $siteId): Collection
    {
        if (filled($siteId)) {
            return collect([$this->siteAuthorizer->siteFor($company, $access, (string) $siteId)->id]);
        }

        return $this->siteAuthorizer->activeSiteIdsFor($company, $access);
    }

    /** @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable} */
    private function day(Company $company, ?string $date): array
    {
        $timezone = $company->timezone ?: 'America/Port-au-Prince';
        $start = $date === null
            ? CarbonImmutable::now($timezone)->startOfDay()
            : CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();

        return [$start->format('Y-m-d'), $start, $start->addDay()];
    }

    /** @param Collection<int, string> $values */
    private function sum(Collection $values): string
    {
        return $this->cash->money((float) $values->sum(static fn ($value): float => (float) $value));
    }

    private function assertCanSeeCash(CompanyUserAccess $access): void
    {
        abort_unless(
            $access->allows('cash.sessions.operate') || $access->allows('cash.reports.read'),
            403,
            'Votre rôle ne permet pas d’accéder à la caisse.',
        );
    }

    private function company(Request $request): Company
    {
        $company = $request->attributes->get('clientele.company');
        abort_unless($company instanceof Company, 500, 'Contexte de société manquant.');

        return $company;
    }

    private function access(Request $request): CompanyUserAccess
    {
        $access = $request->attributes->get('clientele.company_access');
        abort_unless($access instanceof CompanyUserAccess, 500, 'Accès de société manquant.');

        return $access;
    }
}
