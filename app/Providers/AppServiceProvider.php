<?php

namespace App\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        DB::listen(static function (QueryExecuted $query): void {
            if ($query->time >= config('database.slow_query_ms', 250)) {
                Log::warning('Slow database query', [
                    'duration_ms' => $query->time,
                    'connection' => $query->connectionName,
                    'sql' => $query->sql,
                ]);
            }
        });
    }
}
