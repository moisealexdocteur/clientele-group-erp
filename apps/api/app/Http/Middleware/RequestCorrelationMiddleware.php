<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestCorrelationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedRequestId = (string) $request->header('X-Request-Id', '');
        $requestId = Str::isUuid($providedRequestId)
            ? $providedRequestId
            : (string) Str::uuid();

        $request->attributes->set('clientele.request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
