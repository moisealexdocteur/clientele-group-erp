<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Vérifie PostgreSQL et Redis sans exposer de secret';

    public function handle(): int
    {
        try {
            DB::select('select 1');
            $this->line('PostgreSQL: OK');
        } catch (Throwable $exception) {
            $this->error('PostgreSQL: indisponible');

            return self::FAILURE;
        }

        try {
            Redis::connection()->ping();
            $this->line('Redis: OK');
        } catch (Throwable $exception) {
            $this->error('Redis: indisponible');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
