<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:80',
            'password' => 'required|string|max:255',
            'device_id' => 'required|string|max:120',
            'device_name' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = DB::table('auth.users')
            ->select('id', 'company_id', 'branch_id', 'username', 'password_hash', 'first_name', 'last_name', 'email', 'status')
            ->where('username', $request->input('username'))
            ->where('status', 1)
            ->first();

        if (!$user || !Hash::check($request->input('password'), $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $roleContext = $this->resolvePrimaryRoleContext((int) $user->id, (int) $user->company_id);

        $deviceId = trim((string) $request->input('device_id'));
        $refreshToken = ApiToken::makeRefreshToken();
        $refreshTokenHash = ApiToken::hashRefreshToken($refreshToken, $deviceId);
        $refreshExpiresAt = now()->addDays((int) env('REFRESH_TOKEN_TTL_DAYS', 30));
        $accessTtlMinutes = (int) env('ACCESS_TOKEN_TTL_MINUTES', 30);

        // Revoke active refresh tokens for this user+device.
        $deviceHashPrefix = ApiToken::deviceHash($deviceId) . '.%';

        DB::table('auth.refresh_tokens')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('token_hash', 'like', $deviceHashPrefix)
            ->update([
                'revoked_at' => now(),
            ]);

        $sessionId = DB::table('auth.refresh_tokens')->insertGetId([
            'user_id' => $user->id,
            'token_hash' => $refreshTokenHash,
            'expires_at' => $refreshExpiresAt,
            'created_at' => now(),
        ]);

        $accessToken = ApiToken::makeAccessToken([
            'uid' => (int) $user->id,
            'cid' => (int) $user->company_id,
            'bid' => $user->branch_id !== null ? (int) $user->branch_id : null,
            'sid' => (int) $sessionId,
            'did' => ApiToken::deviceHash($deviceId),
        ], $accessTtlMinutes);

        $accessExpiresAt = now()->addMinutes($accessTtlMinutes);

        DB::table('auth.users')
            ->where('id', $user->id)
            ->update([
                'last_login_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $accessToken,
            'access_expires_at' => $accessExpiresAt->toIso8601String(),
            'refresh_token' => $refreshToken,
            'refresh_expires_at' => $refreshExpiresAt->toIso8601String(),
            'session_id' => $sessionId,
            'device_id' => $deviceId,
            'device_name' => $request->input('device_name'),
            'user' => [
                'id' => $user->id,
                'company_id' => $user->company_id,
                'branch_id' => $user->branch_id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role_code' => $roleContext['role_code'],
                'role_profile' => $roleContext['role_profile'],
            ],
        ]);
    }

    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string|min:32|max:255',
            'device_id' => 'required|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deviceId = trim((string) $request->input('device_id'));
        $refreshToken = trim((string) $request->input('refresh_token'));
        $refreshTokenHash = ApiToken::hashRefreshToken($refreshToken, $deviceId);

        $session = DB::table('auth.refresh_tokens as rt')
            ->join('auth.users as u', 'u.id', '=', 'rt.user_id')
            ->select([
                'rt.id as session_id',
                'rt.user_id',
                'rt.token_hash',
                'rt.expires_at',
                'rt.revoked_at',
                'u.company_id',
                'u.branch_id',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.status',
            ])
            ->where('rt.token_hash', $refreshTokenHash)
            ->whereNull('rt.revoked_at')
            ->where('rt.expires_at', '>', now())
            ->where('u.status', 1)
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Invalid refresh token',
            ], 401);
        }

        $roleContext = $this->resolvePrimaryRoleContext((int) $session->user_id, (int) $session->company_id);

        $newRefreshToken = ApiToken::makeRefreshToken();
        $newRefreshHash = ApiToken::hashRefreshToken($newRefreshToken, $deviceId);
        $refreshExpiresAt = now()->addDays((int) env('REFRESH_TOKEN_TTL_DAYS', 30));
        $accessTtlMinutes = (int) env('ACCESS_TOKEN_TTL_MINUTES', 30);

        $newSessionId = null;

        DB::transaction(function () use ($session, $newRefreshHash, $refreshExpiresAt, &$newSessionId) {
            DB::table('auth.refresh_tokens')
                ->where('id', $session->session_id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                ]);

            $newSessionId = DB::table('auth.refresh_tokens')->insertGetId([
                'user_id' => $session->user_id,
                'token_hash' => $newRefreshHash,
                'expires_at' => $refreshExpiresAt,
                'created_at' => now(),
            ]);
        });

        $accessToken = ApiToken::makeAccessToken([
            'uid' => (int) $session->user_id,
            'cid' => (int) $session->company_id,
            'bid' => $session->branch_id !== null ? (int) $session->branch_id : null,
            'sid' => (int) $newSessionId,
            'did' => ApiToken::deviceHash($deviceId),
        ], $accessTtlMinutes);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $accessToken,
            'access_expires_at' => now()->addMinutes($accessTtlMinutes)->toIso8601String(),
            'refresh_token' => $newRefreshToken,
            'refresh_expires_at' => $refreshExpiresAt->toIso8601String(),
            'session_id' => $newSessionId,
            'device_id' => $deviceId,
            'user' => [
                'id' => (int) $session->user_id,
                'company_id' => (int) $session->company_id,
                'branch_id' => $session->branch_id !== null ? (int) $session->branch_id : null,
                'username' => $session->username,
                'first_name' => $session->first_name,
                'last_name' => $session->last_name,
                'email' => $session->email,
                'role_code' => $roleContext['role_code'],
                'role_profile' => $roleContext['role_profile'],
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'company_id' => $user->company_id,
                'branch_id' => $user->branch_id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role_code' => $user->role_code ?? null,
                'role_profile' => $user->role_profile ?? null,
            ],
        ]);
    }

    private function resolvePrimaryRoleContext(int $userId, int $companyId): array
    {
        $this->ensureCompanyRoleProfilesTable();

        $row = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($companyId) {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', $companyId);
            })
            ->where('ur.user_id', $userId)
            ->where('r.company_id', $companyId)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code', 'crp.functional_profile as role_profile')
            ->first();

        return [
            'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
            'role_profile' => $row && $row->role_profile !== null ? (string) $row->role_profile : null,
        ];
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

    public function logout(Request $request)
    {
        $sessionId = $request->attributes->get('auth_session_id');

        if ($sessionId) {
            DB::table('auth.refresh_tokens')
                ->where('id', $sessionId)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Logged out',
        ]);
    }
}
