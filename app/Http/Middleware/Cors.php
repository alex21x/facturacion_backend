<?php

namespace App\Http\Middleware;

use Closure;

class Cors
{
    public function handle($request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigins = $this->allowedOrigins();

        $isAllowed = $origin && in_array($origin, $allowedOrigins, true);

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
}
