<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Support\Cash\CashPresenter;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListCashRegisters extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CashPresenter $presenter,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    /** Caisses des adresses autorisées, avec la session ouverte s'il y en a une. */
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $this->presenter->assertCanSeeCash($access);

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
                $last = $session === null ? $this->presenter->lastClosedSession($company, $register->id) : null;

                return [
                    'id' => $register->id,
                    'code' => $register->code,
                    'name' => $register->name,
                    'site' => ['id' => $register->site?->id, 'name' => $register->site?->name],
                    'session' => $session === null ? null : $this->presenter->sessionPayload($session, $access, $request->user()),
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
}
