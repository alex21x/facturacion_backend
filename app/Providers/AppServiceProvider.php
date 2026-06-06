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

            // Skip telemetry write queries to avoid observability feedback noise.
            if ($this->shouldSkipSlowSqlLog((string) $query->sql)) {
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

            $signature = sha1((string) $query->connectionName . '|' . preg_replace('/\s+/', ' ', trim((string) $query->sql)));
            $seenSignatures = $request->attributes->get('perf_slow_sql_seen_signatures', []);
            if (!is_array($seenSignatures)) {
                $seenSignatures = [];
            }

            // Avoid repeated logs for the exact same SQL shape inside one request.
            if (isset($seenSignatures[$signature])) {
                return;
            }

            $maxLogsPerRequest = $this->slowSqlMaxLogsPerRequest();
            $loggedCount = (int) $request->attributes->get('perf_slow_sql_logged_count', 0);
            if ($loggedCount >= $maxLogsPerRequest) {
                $request->attributes->set(
                    'perf_slow_sql_suppressed_count',
                    (int) $request->attributes->get('perf_slow_sql_suppressed_count', 0) + 1
                );

                return;
            }

            $seenSignatures[$signature] = true;
            $request->attributes->set('perf_slow_sql_seen_signatures', $seenSignatures);
            $request->attributes->set('perf_slow_sql_logged_count', $loggedCount + 1);

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
                'sql_log_slot' => $loggedCount + 1,
                'sql_log_cap' => $maxLogsPerRequest,
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
        $threshold = is_numeric($value) ? (float) $value : 350.0;

        return $threshold > 0 ? $threshold : 350.0;
    }

    private function slowSqlMaxLogsPerRequest(): int
    {
        $value = env('OPS_SLOW_SQL_MAX_LOGS_PER_REQUEST');
        $max = is_numeric($value) ? (int) $value : 2;

        return $max > 0 ? $max : 2;
    }

    private function shouldSkipSlowSqlLog(string $sql): bool
    {
        $normalized = strtolower($sql);

        // Covers quoted/unquoted forms, e.g. ops.http_endpoint_latency_samples,
        // "ops"."http_endpoint_latency_samples", `ops`.`http_endpoint_latency_samples`.
        return str_contains($normalized, 'http_endpoint_latency_samples');
    }
}
