<?php

namespace App\Http\Middleware;

use App\Models\ApiAccessToken;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateApiToken
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        $authorization = (string) $request->header('Authorization', '');

        if (! Str::startsWith($authorization, 'Bearer ')) {
            return $this->unauthenticated();
        }

        $token = ApiAccessToken::findActive(trim(Str::after($authorization, 'Bearer ')));

        if ($token === null || $token->user === null || ! $token->user->is_active) {
            if ($token !== null) {
                $token->revoke();
            }

            return $this->unauthenticated();
        }

        $token->registerUsage($request);
        $request->setUserResolver(static fn () => $token->user);
        $request->attributes->set('clientele.access_token', $token);

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        $this->audit->record(
            eventType: 'auth.api_token_refused',
            metadata: ['reason' => 'missing_or_invalid_token'],
        );

        return response()->json([
            'message' => 'Votre session est absente, expirée ou révoquée.',
        ], 401);
    }
}
