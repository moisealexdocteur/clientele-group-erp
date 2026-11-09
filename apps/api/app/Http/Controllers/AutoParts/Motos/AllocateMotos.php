<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Support\AutoParts\MotoAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Attribue les motos disponibles aux commandes en attente, dans l'ordre de paiement. */
final class AllocateMotos extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(private readonly MotoAllocator $allocator)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $actor = $request->user();
        $assigned = DB::transaction(fn (): int => $this->allocator->allocate($company, $actor));

        return response()->json(['data' => ['assigned' => $assigned]]);
    }
}
