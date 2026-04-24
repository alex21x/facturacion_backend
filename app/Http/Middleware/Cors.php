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

        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);

            if ($isAllowed) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Vary', 'Origin');
            }

            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
            $response->headers->set('Access-Control-Max-Age', '86400');

            return $response;
        }

        $response = $next($request);

        if ($isAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');

        return $response;
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
        if ((string) env('APP_ENV', 'production') === 'local') {
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
