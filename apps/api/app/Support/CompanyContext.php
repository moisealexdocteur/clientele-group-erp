<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Exécute une opération de société dans une seule transaction PostgreSQL.
 * Les politiques RLS lisent la variable locale app.current_company_id.
 */
final class CompanyContext
{
    public function within(string $companyId, Closure $operation): mixed
    {
        if (! Str::isUuid($companyId)) {
            throw new InvalidArgumentException('Le contexte de société doit être un UUID valide.');
        }

        return DB::transaction(function () use ($companyId, $operation): mixed {
            if (DB::getDriverName() !== 'pgsql') {
                return $operation();
            }

            DB::select(
                "select set_config('app.current_company_id', ?, true)",
                [$companyId],
            );

            return $operation();
        });
    }
}
