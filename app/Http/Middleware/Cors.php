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

        // For installer/local deployments with LAN access enabled (0.0.0.0),
        // allow browser origins from localhost/private IPv4 on any port.
        if ((string) env('APP_ENV', 'production') !== 'local') {
            return false;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            return false;
        }

        // For local origins, accept ANY port (no port filtering)
        return $this->isLocalOriginHost($host);
    }

    private function isLocalOriginHost(string $host): bool
    {
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return true;
        }

        // Support Windows/LAN hostname access used by remote clients,
        // e.g. http://PC-FACTURACION:5180 or http://PC-NAME:8080
        // (any hostname without dots, or with dots for internal domains)
        if (preg_match('/^[a-z0-9-]+$/i', $host)) {
            return true;
        }

        // Support internal DNS names (office/local domains).
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $host)) {
            return true;
        }

        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Support all private IPv4 ranges (RFC 1918 + link-local)
        if (strpos($host, '10.') === 0) {
            return true;
        }

        if (strpos($host, '192.168.') === 0) {
            return true;
        }

        if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host)) {
            return true;
        }

        // Support link-local (169.254.x.x)
        if (strpos($host, '169.254.') === 0) {
            return true;
        }

        return false;
    }
}
