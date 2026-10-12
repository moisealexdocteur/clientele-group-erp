<?php

namespace App\Support\Cash;

use App\Models\CarRentalPayment;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\User;
use App\Support\CashSessionService;
use App\Support\CompanySiteAuthorizer;
use App\Support\Money;
use App\Support\ReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Caisse : présentation des sessions et des mouvements, lecture dans le
 * contexte de société et d'adresse, journée de Cap-Haïtien et totaux en centimes.
 */
final class CashPresenter
{
    public const MOVEMENT_LABELS = [
        'rental_payment' => 'Paiement de location',
        'deposit_payment' => 'Dépôt de garantie reçu',
        'deposit_refund' => 'Dépôt de garantie rendu',
    ];

    public function __construct(
        private readonly CashSessionService $cash,
        private readonly ReceiptService $receipts,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    /** @return array<string, mixed> */
    public function sessionPayload(CashSession $session, CompanyUserAccess $access, mixed $actor, bool $withMovements = false): array
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

    public function canClose(CashSession $session, CompanyUserAccess $access, ?User $actor): bool
    {
        if (! $access->allows('cash.sessions.operate') && ! $access->allows('cash.sessions.approve')) {
            return false;
        }

        return $access->allows('cash.sessions.approve')
            || ($actor !== null && $session->opened_by === $actor->id);
    }

    public function pendingCashPayments(CashSession $session): int
    {
        return CarRentalPayment::query()
            ->where('company_id', $session->company_id)
            ->where('cash_session_id', $session->id)
            ->where('status', 'submitted')
            ->count();
    }

    public function lastClosedSession(Company $company, string $registerId): ?CashSession
    {
        return CashSession::query()
            ->where('company_id', $company->id)
            ->where('cash_register_id', $registerId)
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->first();
    }

    public function sessionFor(Company $company, CompanyUserAccess $access, string $session, bool $lock = false): CashSession
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
    public function siteIds(Company $company, CompanyUserAccess $access, ?string $siteId): Collection
    {
        if (filled($siteId)) {
            return collect([$this->siteAuthorizer->siteFor($company, $access, (string) $siteId)->id]);
        }

        return $this->siteAuthorizer->activeSiteIdsFor($company, $access);
    }

    /** @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable} */
    public function day(Company $company, ?string $date): array
    {
        $timezone = $company->timezone ?: 'America/Port-au-Prince';
        $start = $date === null
            ? CarbonImmutable::now($timezone)->startOfDay()
            : CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();

        return [$start->format('Y-m-d'), $start, $start->addDay()];
    }

    /** @param Collection<int, string> $values */
    public function sum(Collection $values): string
    {
        return Money::fromCents((int) $values->sum(static fn ($value): int => Money::toCents((string) $value)));
    }

    public function assertCanSeeCash(CompanyUserAccess $access): void
    {
        abort_unless(
            $access->allows('cash.sessions.operate') || $access->allows('cash.reports.read'),
            403,
            'Votre rôle ne permet pas d’accéder à la caisse.',
        );
    }
}
