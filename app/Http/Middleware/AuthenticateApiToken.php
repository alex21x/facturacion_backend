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

        $this->ensureCompanyRoleProfilesTable();

        $roleRow = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($user) {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', (int) $user->company_id);
            })
            ->where('ur.user_id', (int) $user->id)
            ->where('r.company_id', (int) $user->company_id)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code', 'crp.functional_profile as role_profile')
            ->first();

        $user->role_code = $roleRow && $roleRow->role_code !== null ? (string) $roleRow->role_code : null;
        $user->role_profile = $roleRow && $roleRow->role_profile !== null ? (string) $roleRow->role_profile : null;

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_session_id', (int) $session->id);
        $request->attributes->set('auth_claims', $claims);

        return $next($request);
    }

    private function ensureCompanyRoleProfilesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_role_profiles (
                company_id BIGINT NOT NULL,
                role_id BIGINT NOT NULL,
                functional_profile VARCHAR(20) NULL,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, role_id)
            )'
        );
    }
}
