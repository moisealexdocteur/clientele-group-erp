<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class CompanyContextMiddleware
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        $companyId = (string) $request->header('X-Clientele-Company-Id', '');

        if (! Str::isUuid($companyId)) {
            return response()->json([
                'message' => 'Sélectionnez une société autorisée avant de continuer.',
            ], 422);
        }

        $user = $request->user();
        $access = CompanyUserAccess::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user?->id)
            ->where('is_active', true)
            ->first();

        $company = $access === null
            ? null
            : Company::query()
                ->whereKey($companyId)
                ->where('is_active', true)
                ->first();

        if ($access === null || $company === null) {
            $this->audit->record(
                eventType: 'authorization.company_context_refused',
                actorId: $user?->id,
                actorType: $user === null ? 'SYSTEM' : 'USER',
                subjectType: Company::class,
                subjectId: $companyId,
                metadata: ['reason' => 'company_access_not_granted'],
            );

            return response()->json([
                'message' => 'Cette société n’est pas autorisée pour votre compte.',
            ], 403);
        }

        $request->attributes->set('clientele.company', $company);
        $request->attributes->set('clientele.company_access', $access);

        return $this->companyContext->within(
            $companyId,
            static fn (): Response => $next($request),
        );
    }
}
