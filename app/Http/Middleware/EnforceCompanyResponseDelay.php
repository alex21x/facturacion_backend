<?php

namespace App\Http\Middleware;

use App\Services\AppConfig\CompanyRateLimitService;
use Closure;
use Illuminate\Support\Facades\Cache;

class EnforceCompanyResponseDelay
{
    public function __construct(
        private CompanyRateLimitService $companyRateLimitService
    ) {
    }

    public function handle($request, Closure $next)
    {
        if (strtoupper((string) $request->getMethod()) === 'OPTIONS') {
            return $next($request);
        }

        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($authUser->company_id ?? 0);
        if ($companyId <= 0) {
            return $next($request);
        }

        $cacheKey = sprintf('%s:%d', config('company_response_delay.cache_key_prefix'), $companyId);
        $settings = Cache::remember(
            $cacheKey,
            config('company_response_delay.cache_ttl_seconds'),
            fn () => $this->companyRateLimitService->resolveResponseDelay($companyId)
        );

        if ($settings['is_enabled'] && $settings['delay_ms'] > 0 && !$settings['has_active_subscription']) {
            usleep($settings['delay_ms'] * 1000);
        }

        return $next($request);
    }
}
