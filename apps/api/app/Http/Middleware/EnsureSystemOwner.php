<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protège les réglages transversaux du groupe. Les rôles de société ne
 * suffisent jamais à créer ou à consulter les sociétés, sites et caisses.
 */
final class EnsureSystemOwner
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->system_role !== 'owner') {
            $this->audit->record(
                eventType: 'authorization.system_configuration_refused',
                actorId: $user?->id,
                actorType: $user === null ? 'SYSTEM' : 'USER',
                subjectType: User::class,
                subjectId: $user?->id,
                metadata: ['required_system_role' => 'owner'],
            );

            return response()->json([
                'message' => 'Cette fonction est réservée au propriétaire du système.',
            ], 403);
        }

        return $next($request);
    }
}
