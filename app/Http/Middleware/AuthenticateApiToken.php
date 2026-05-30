<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthSessionService;
use App\Support\ApiToken;
use Closure;

class AuthenticateApiToken
{
    public function __construct(
        private AuthSessionService $authSessionService
    ) {
    }

    public function handle($request, Closure $next)
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $claims = ApiToken::parseAccessToken($bearerToken);

        if (!$claims || !isset($claims['uid']) || !isset($claims['sid'])) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $context = $this->authSessionService->resolveAuthenticatedRequestContext((int) $claims['uid'], (int) $claims['sid']);

        if (!$context) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = $context['user'];
        $session = $context['session'];

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_session_id', (int) $session->id);
        $request->attributes->set('auth_claims', $claims);
        $request->attributes->set('auth_device_id', property_exists($session, 'device_id') ? $session->device_id : null);
        $request->attributes->set('auth_device_name', property_exists($session, 'device_name') ? $session->device_name : null);

        return $next($request);
    }
}
