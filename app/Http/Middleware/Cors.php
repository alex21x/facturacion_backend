<?php

namespace App\Http\Middleware;

use Closure;

class Cors
{
    public function handle($request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigins = $this->allowedOrigins();

        $isAllowed = $this->isAllowedOrigin($origin, $allowedOrigins);
        $allowAllLocal = $this->allowAllOriginsInLocal();
        // Echo origin instead of wildcard to maximize browser compatibility
        // (notably Private Network Access preflights in Chromium-based browsers).
        $allowOriginHeader = ($allowAllLocal || $isAllowed) && $origin ? $origin : null;
        $requestedHeaders = (string) $request->headers->get('Access-Control-Request-Headers', '');
        $allowHeaders = $requestedHeaders !== ''
            ? $requestedHeaders
            : 'Content-Type, Authorization, X-Requested-With, Accept, Origin';
        $isPrivateNetworkPreflight = strtolower((string) $request->headers->get('Access-Control-Request-Private-Network', '')) === 'true';

        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);

            if ($allowOriginHeader !== null) {
                $response->headers->set('Access-Control-Allow-Origin', $allowOriginHeader);
                $response->headers->set('Vary', 'Origin');
            }

            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', $allowHeaders);
            $response->headers->set('Access-Control-Max-Age', '86400');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition, X-Bridge-Endpoint');
            if ($isPrivateNetworkPreflight) {
                $response->headers->set('Access-Control-Allow-Private-Network', 'true');
            }

            return $response;
        }

        $response = $next($request);

        if ($allowOriginHeader !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $allowOriginHeader);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', $allowHeaders);
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition, X-Bridge-Endpoint');
        if ($isPrivateNetworkPreflight) {
            $response->headers->set('Access-Control-Allow-Private-Network', 'true');
        }

        return $response;
    }

    private function allowAllOriginsInLocal(): bool
    {
        $appEnv = (string) env('APP_ENV', 'production');
        if ($appEnv === 'local') {
            return true;
        }

        $flag = strtolower((string) env('CORS_ALLOW_ALL_ORIGINS', 'false'));
        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }

    private function allowedOrigins(): array
    {
        $origins = [
            'http://127.0.0.1:5173',
            'http://localhost:5173',
            'http://127.0.0.1:5174',
            'http://localhost:5174',
            'http://127.0.0.1:5178',
            'http://localhost:5178',
            'http://127.0.0.1:5179',
            'http://localhost:5179',
            (string) env('FRONTEND_URL', ''),
            (string) env('FRONTEND_APP_URL', ''),
            (string) env('FRONTEND_ADMIN_URL', ''),
        ];

        return array_values(array_filter(array_unique($origins)));
    }

    private function isAllowedOrigin(?string $origin, array $allowedOrigins): bool
    {
        if (!$origin) {
            return false;
        }

        if (in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        // For LOCAL environment (installer/local development mode):
        // Accept ANY origin from ANY IP/hostname/port without restriction.
        // This is safe because APP_ENV=local is ONLY for local/network development,
        // NOT for production. Installers use this mode to support:
        //  - Localhost (127.0.0.1, localhost)
        //  - LAN access (192.168.x, 10.x, 172.16-31.x)
        //  - Private networks (any ISP/carrier IP on local network)
        //  - Mobile clients (any IP on same network via WiFi)
        //  - Hostnames (PC-NAME:port)
        //  - Different ports (5173, 5180, 5181, 8080, any port)
        if ($this->allowAllOriginsInLocal()) {
            $parts = parse_url($origin);
            if (is_array($parts) && isset($parts['scheme'])) {
                $scheme = strtolower((string) $parts['scheme']);
                // Accept http and https only (prevent javascript: or data: origins)
                if ($scheme === 'http' || $scheme === 'https') {
                    return true;
                }
            }
        }

        return false;
    }
}
