<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'clientele-group-erp-api',
            'time_utc' => now()->utc()->toIso8601String(),
        ]);
    }
}
