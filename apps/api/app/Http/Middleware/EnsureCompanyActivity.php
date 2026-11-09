<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les écrans d'une activité ne s'appliquent qu'aux sociétés de cette
 * activité : une société Car Rental n'a ni catalogue de pièces ni ventes
 * au comptoir, même pour le propriétaire.
 */
final class EnsureCompanyActivity
{
    public function handle(Request $request, Closure $next, string $activity): Response|JsonResponse
    {
        $company = $request->attributes->get('clientele.company');

        if (! $company instanceof Company || $company->activity !== $activity) {
            return response()->json([
                'message' => 'Cette fonction ne s’applique pas à l’activité de la société active.',
            ], 404);
        }

        return $next($request);
    }
}
