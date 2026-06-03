<?php

namespace App\Http\Middleware;

use App\Jobs\PersistEndpointLatencySampleJob;
use App\Services\Ops\OpsLatencyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CaptureEndpointLatency
{
    private static ?bool $tableAvailable = null;

    private float $start = 0.0;

    private string $requestId = '';

    public function __construct(
        private OpsLatencyService $opsLatencyService
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        $this->start = microtime(true);
        $this->requestId = $this->resolveRequestId($request);
        $request->attributes->set('perf_request_id', $this->requestId);

        $response = $next($request);
        if (method_exists($response, 'headers')) {
            $response->headers->set('X-Request-Id', $this->requestId);
        }

        return $response;
    }

    public function terminate(Request $request, $response): void
    {
        $statusCode = method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : 0;

        $this->persistSample($request, $statusCode, $this->start);
        $this->logSlowRequest($request, $statusCode, $this->start);
    }

    private function persistSample(Request $request, int $statusCode, float $start): void
    {
        if (!$this->isCaptureEnabled()) {
            return;
        }

        if (!$this->canCapture($request)) {
            return;
        }

        if (!$this->isTableAvailable()) {
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
            PersistEndpointLatencySampleJob::dispatch([
                'company_id' => $companyId,
                'method' => $method,
                'route_uri' => $routeUri,
                'endpoint_key' => $method . ' ' . $routeUri,
                'status_code' => $statusCode,
                'duration_ms' => round($durationMs, 3),
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->onQueue('ops-latency');
        } catch (\Throwable $e) {
            // Keep observability best-effort and never fail the request.
        }
    }

    private function canCapture(Request $request): bool
    {
        if (strtoupper((string) $request->method()) === 'OPTIONS') {
            return false;
        }

        $path = '/' . ltrim((string) $request->path(), '/');

        if (!str_starts_with($path, '/api/')) {
            return false;
        }

        if (str_starts_with($path, '/api/ops/latency/summary')) {
            return false;
        }

        return true;
    }

    private function isCaptureEnabled(): bool
    {
        $flag = env('OPS_CAPTURE_ENDPOINT_LATENCY');
        if ($flag === null) {
            return !app()->environment('local');
        }

        $normalized = strtolower(trim((string) $flag));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function isTableAvailable(): bool
    {
        if (self::$tableAvailable !== null) {
            return self::$tableAvailable;
        }

        try {
            self::$tableAvailable = $this->opsLatencyService->isSamplesTableAvailable();
        } catch (\Throwable $e) {
            self::$tableAvailable = false;
        }

        return self::$tableAvailable;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = trim((string) $request->headers->get('X-Request-Id', ''));
        if ($incoming !== '') {
            return substr($incoming, 0, 64);
        }

        return bin2hex(random_bytes(8));
    }

    private function logSlowRequest(Request $request, int $statusCode, float $start): void
    {
        if (!$this->canCapture($request)) {
            return;
        }

        if (!$this->isSlowRequestLoggingEnabled()) {
            return;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $thresholdMs = $this->slowRequestThresholdMs();
        if ($durationMs < $thresholdMs) {
            return;
        }

        $authUser = $request->attributes->get('auth_user');
        $companyId = is_object($authUser) && isset($authUser->company_id)
            ? (int) $authUser->company_id
            : null;

        Log::warning('perf.slow_request', [
            'request_id' => $this->requestId,
            'company_id' => $companyId,
            'method' => strtoupper((string) $request->method()),
            'path' => '/' . ltrim((string) $request->path(), '/'),
            'status_code' => $statusCode,
            'duration_ms' => round($durationMs, 3),
            'threshold_ms' => $thresholdMs,
        ]);
    }

    private function isSlowRequestLoggingEnabled(): bool
    {
        $flag = env('OPS_LOG_SLOW_REQUESTS');
        if ($flag === null) {
            return !app()->environment('local');
        }

        $normalized = strtolower(trim((string) $flag));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function slowRequestThresholdMs(): float
    {
        $value = env('OPS_SLOW_REQUEST_MS');
        $threshold = is_numeric($value) ? (float) $value : 1200.0;

        return $threshold > 0 ? $threshold : 1200.0;
    }
}
