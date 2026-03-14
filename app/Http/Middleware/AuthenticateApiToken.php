<?php

namespace App\Http\Middleware;

use App\Support\ApiToken;
use Closure;
use Illuminate\Support\Facades\DB;

class AuthenticateApiToken
{
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

        $session = DB::table('auth.refresh_tokens')
            ->select('id', 'user_id', 'expires_at', 'revoked_at')
            ->where('id', (int) $claims['sid'])
            ->where('user_id', (int) $claims['uid'])
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = DB::table('auth.users as u')
            ->select('u.id', 'u.company_id', 'u.branch_id', 'u.username', 'u.first_name', 'u.last_name', 'u.email', 'u.status')
            ->where('u.id', (int) $claims['uid'])
            ->where('u.status', 1)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_session_id', (int) $session->id);
        $request->attributes->set('auth_claims', $claims);

        return $next($request);
    }
}
