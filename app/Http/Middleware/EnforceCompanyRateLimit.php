<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnforceCompanyRateLimit
{
    private const CACHE_TTL_SECONDS = 60;
    private const SCHEMA_CACHE_TTL_SECONDS = 600;

    public function handle($request, Closure $next)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser || !isset($authUser->company_id)) {
            return $next($request);
        }

        $companyId = (int) $authUser->company_id;
        if ($companyId <= 0) {
            return $next($request);
        }

        $profile = $this->resolveProfile($request);
        $limitPerMinute = $this->resolveCompanyLimit($companyId, $profile);
        if ($limitPerMinute <= 0) {
            return $next($request);
        }

        $minuteBucket = now()->format('YmdHi');
        $requestKey = sprintf('tenant_rate:%d:%s:%s', $companyId, $profile, $minuteBucket);

        Cache::add($requestKey, 0, self::CACHE_TTL_SECONDS + 5);

        $attempts = (int) Cache::increment($requestKey);
        $remaining = max(0, $limitPerMinute - $attempts);

        if ($attempts > $limitPerMinute) {
            return response()->json([
                'message' => 'Demasiadas solicitudes para esta empresa. Intenta nuevamente en unos segundos.',
            ], 429, [
                'X-RateLimit-Limit' => (string) $limitPerMinute,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Profile' => $profile,
                'Retry-After' => '60',
            ]);
        }

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limitPerMinute);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Profile', $profile);

        return $response;
    }

    private function resolveProfile($request): string
    {
        $path = trim((string) $request->path(), '/');
        $method = strtoupper((string) $request->getMethod());

        if (preg_match('#^api/(inventory-pro|reports)(/|$)#', $path) === 1) {
            return 'reports';
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return 'write';
        }

        return 'read';
    }

    private function resolveCompanyLimit(int $companyId, string $profile): int
    {
        $default = $this->resolveDefaultLimitByProfile($profile);

        $cacheKey = sprintf('tenant_rate_limit_cfg:%d:%s', $companyId, $profile);

        return (int) Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($companyId, $profile, $default) {
            try {
                $query = DB::table('appcfg.company_rate_limits')->where('company_id', $companyId);

                $hasProfileColumns = $this->hasProfileColumns();
                if ($hasProfileColumns) {
                    $row = $query
                        ->select(
                            'requests_per_minute',
                            'requests_per_minute_read',
                            'requests_per_minute_write',
                            'requests_per_minute_reports',
                            'is_enabled'
                        )
                        ->first();
                } else {
                    $row = $query
                        ->select('requests_per_minute', 'is_enabled')
                        ->first();
                }

                if (!$row) {
                    return $default;
                }

                if ((int) ($row->is_enabled ?? 1) !== 1) {
                    return 0;
                }

                $profileColumn = 'requests_per_minute_' . $profile;
                $configured = 0;

                if ($hasProfileColumns && isset($row->{$profileColumn})) {
                    $configured = (int) ($row->{$profileColumn} ?? 0);
                }

                if ($configured <= 0) {
                    $configured = (int) ($row->requests_per_minute ?? 0);
                }

                return $configured > 0 ? $configured : $default;
            } catch (\Throwable $e) {
                // Fallback to default limit when config table does not exist yet.
                return $default;
            }
        });
    }

    private function resolveDefaultLimitByProfile(string $profile): int
    {
        if ($profile === 'reports') {
            return (int) env('DEFAULT_COMPANY_RATE_LIMIT_REPORTS_PER_MINUTE', 900);
        }

        if ($profile === 'write') {
            return (int) env('DEFAULT_COMPANY_RATE_LIMIT_WRITE_PER_MINUTE', 2400);
        }

        return (int) env('DEFAULT_COMPANY_RATE_LIMIT_PER_MINUTE', 3600);
    }

    private function hasProfileColumns(): bool
    {
        return (bool) Cache::remember('tenant_rate_limit_schema:profile_columns', self::SCHEMA_CACHE_TTL_SECONDS, function () {
            try {
                return Schema::hasColumns('appcfg.company_rate_limits', [
                    'requests_per_minute_read',
                    'requests_per_minute_write',
                    'requests_per_minute_reports',
                ]);
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
}
