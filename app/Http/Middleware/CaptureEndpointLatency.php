<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CaptureEndpointLatency
{
    private static ?bool $tableAvailable = null;

    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);

        $this->persistSample($request, $response->getStatusCode(), $start);

        return $response;
    }

    private function persistSample(Request $request, int $statusCode, float $start): void
    {
        if (!$this->canCapture($request)) {
            return;
        }

        if (!self::isTableAvailable()) {
            return;
        }

        $route = $request->route();
        $routeUri = $route ? (string) $route->uri() : ltrim((string) $request->path(), '/');
        $routeUri = $routeUri !== '' ? '/' . ltrim($routeUri, '/') : '/';

        $method = strtoupper((string) $request->method());
        $durationMs = (microtime(true) - $start) * 1000;
        $authUser = $request->attributes->get('auth_user');
        $companyId = is_object($authUser) && isset($authUser->company_id)
            ? (int) $authUser->company_id
            : null;

        try {
            DB::table('ops.http_endpoint_latency_samples')->insert([
                'company_id' => $companyId,
                'method' => $method,
                'route_uri' => $routeUri,
                'endpoint_key' => $method . ' ' . $routeUri,
                'status_code' => $statusCode,
                'duration_ms' => round($durationMs, 3),
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Keep observability best-effort and never fail the request.
        }
    }

    private function canCapture(Request $request): bool
    {
        $path = '/' . ltrim((string) $request->path(), '/');

        if (!str_starts_with($path, '/api/')) {
            return false;
        }

        if (str_starts_with($path, '/api/ops/latency/summary')) {
            return false;
        }

        return true;
    }

    private static function isTableAvailable(): bool
    {
        if (self::$tableAvailable !== null) {
            return self::$tableAvailable;
        }

        try {
            self::$tableAvailable = DB::table('information_schema.tables')
                ->where('table_schema', 'ops')
                ->where('table_name', 'http_endpoint_latency_samples')
                ->exists();
        } catch (\Throwable $e) {
            self::$tableAvailable = false;
        }

        return self::$tableAvailable;
    }
}
