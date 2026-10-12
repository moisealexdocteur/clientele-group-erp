<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Support\CashSessionService;
use App\Support\Cash\CashPresenter;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ShowDailyCashReport extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CashPresenter $presenter,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    /**
     * Rapport journalier : sessions ouvertes le jour choisi (heure de
     * Cap-Haïtien) dans les adresses autorisées, espèces par devise, écarts,
     * autres règlements approuvés et réimpressions de reçus.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'site_id' => ['nullable', 'uuid'],
        ]);

        [$day, $start, $end] = $this->presenter->day($company, $data['date'] ?? null);
        $siteIds = $this->presenter->siteIds($company, $access, $data['site_id'] ?? null);
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

        $sessionPayloads = $sessions->map(fn (CashSession $session): array => $this->presenter->sessionPayload($session, $access, $request->user(), true))->values();

        $totals = [];
        foreach (CashSessionService::CURRENCIES as $currency) {
            $key = strtolower($currency);
            $closed = $sessions->where('status', 'closed');
            $totals[$currency] = [
                'opening' => $this->presenter->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['opening'])),
                'in' => $this->presenter->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['in'])),
                'out' => $this->presenter->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['out'])),
                'expected' => $this->presenter->sum($sessionPayloads->map(static fn (array $row): string => $row['totals'][$currency]['expected'])),
                'declared' => $this->presenter->sum($closed->map(static fn (CashSession $session): string => (string) $session->{"declared_{$key}"})),
                'variance' => $this->presenter->sum($closed->map(static fn (CashSession $session): string => (string) $session->{"variance_{$key}"})),
            ];
        }

        $byKind = CashMovement::query()
            ->where('company_id', $company->id)
            ->whereIn('cash_session_id', $sessions->pluck('id'))
            ->get(['kind', 'direction', 'currency', 'amount'])
            ->groupBy(static fn (CashMovement $movement): string => $movement->kind . '|' . $movement->currency)
            ->map(fn (Collection $group, string $key): array => [
                'kind' => explode('|', $key)[0],
                'label' => CashPresenter::MOVEMENT_LABELS[explode('|', $key)[0]] ?? explode('|', $key)[0],
                'direction' => $group->first()->direction,
                'currency' => explode('|', $key)[1],
                'count' => $group->count(),
                'amount' => $this->presenter->sum($group->map(static fn (CashMovement $movement): string => (string) $movement->amount)),
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
                'amount' => $this->presenter->sum($group->map(static fn (CarRentalPayment $payment): string => (string) $payment->amount)),
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
}
