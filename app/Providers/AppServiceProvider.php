<?php

namespace App\Providers;

use App\Contracts\PadronLookupGateway;
use App\Contracts\TaxBridgeGateway;
use App\Infrastructure\External\MundosoftPadronLookupGateway;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use Throwable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(PadronLookupGateway::class, MundosoftPadronLookupGateway::class);
        $this->app->bind(TaxBridgeGateway::class, TaxBridgeService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $timezone = (string) config('app.timezone', 'America/Lima');

        // Keep PHP runtime aligned with app timezone for all modules.
        if ($timezone !== '') {
            date_default_timezone_set($timezone);
        }

        // Enforce DB session timezone on PostgreSQL to avoid date shifts when persisting dates/timestamps.
        if ((string) config('database.default') === 'pgsql' && $timezone !== '') {
            $escapedTimezone = str_replace("'", "''", $timezone);
            try {
                DB::unprepared("SET TIME ZONE '{$escapedTimezone}'");
            } catch (Throwable $exception) {
                // Keep app booting in local/dev even when DB credentials are temporarily invalid.
                Log::warning('Skipping DB timezone session setup during boot', [
                    'connection' => (string) config('database.default'),
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        $this->registerSlowQueryListener();
    }

    private function registerSlowQueryListener(): void
    {
        if (!$this->isSlowSqlLoggingEnabled()) {
            return;
        }

        $thresholdMs = $this->slowSqlThresholdMs();

        DB::listen(function (QueryExecuted $query) use ($thresholdMs): void {
            if ((float) $query->time < $thresholdMs) {
                return;
            }

            $request = app()->bound('request') ? app('request') : null;
            if (!$request instanceof Request) {
                return;
            }

            $path = '/' . ltrim((string) $request->path(), '/');
            if (!str_starts_with($path, '/api/')) {
                return;
            }

            $authUser = $request->attributes->get('auth_user');
            $companyId = is_object($authUser) && isset($authUser->company_id)
                ? (int) $authUser->company_id
                : null;

            Log::warning('perf.slow_sql', [
                'request_id' => (string) $request->attributes->get('perf_request_id', ''),
                'company_id' => $companyId,
                'method' => strtoupper((string) $request->method()),
                'path' => $path,
                'connection' => (string) $query->connectionName,
                'time_ms' => round((float) $query->time, 3),
                'threshold_ms' => $thresholdMs,
                'sql' => $query->sql,
                'bindings_count' => count($query->bindings),
            ]);
        });
    }

    private function isSlowSqlLoggingEnabled(): bool
    {
        $flag = env('OPS_LOG_SLOW_SQL');
        if ($flag === null) {
            return !app()->environment('local');
        }

        $normalized = strtolower(trim((string) $flag));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function slowSqlThresholdMs(): float
    {
        $value = env('OPS_SLOW_SQL_MS');
        $threshold = is_numeric($value) ? (float) $value : 250.0;

        return $threshold > 0 ? $threshold : 250.0;
    }
}
