<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class BootstrapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'application' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
                'version' => '0.4.0-alpha.1',
            ],
            'display' => [
                'locale' => 'fr-HT',
                'timezone' => 'America/Port-au-Prince',
                'timezone_label' => 'Cap-Haïtien, Haïti',
                'currencies' => ['HTG', 'USD'],
            ],
            'pilot' => [
                'module' => 'car_rental',
                'label' => 'Clientèle Rent a Car',
                'state' => 'foundation',
                'production_data_available' => false,
            ],
        ]);
    }
}
