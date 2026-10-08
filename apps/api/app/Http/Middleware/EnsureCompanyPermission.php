<?php

namespace App\Http\Middleware;

use App\Models\CompanyUserAccess;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCompanyPermission
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(Request $request, Closure $next, string $permission): Response|JsonResponse
    {
        $access = $request->attributes->get('clientele.company_access');
        $company = $request->attributes->get('clientele.company');

        if (! $access instanceof CompanyUserAccess || ! $access->allows($permission)) {
            $this->audit->record(
                eventType: 'authorization.permission_refused',
                companyId: $company?->id,
                actorId: $request->user()?->id,
                actorType: $request->user() === null ? 'SYSTEM' : 'USER',
                metadata: ['permission' => $permission],
            );

            return response()->json([
                'message' => 'Votre rôle ne permet pas cette action.',
            ], 403);
        }

        return $next($request);
    }
}
