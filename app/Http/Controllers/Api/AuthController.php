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
            'company_access_slug' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deviceId = trim((string) $request->input('device_id'));
        $requestedAccessSlug = strtolower(trim((string) $request->input('company_access_slug', '')));

        if ($requestedAccessSlug !== '' && !$this->tableExists('appcfg', 'company_access_links')) {
            return response()->json([
                'message' => 'No fue posible iniciar sesion en este momento. Intenta nuevamente o contacta a soporte.',
            ], 401);
        }

        $userQuery = DB::table('auth.users as u')
            ->join('core.companies as c', 'c.id', '=', 'u.company_id')
            ->select('u.id', 'u.company_id', 'u.branch_id', 'u.username', 'u.password_hash', 'u.first_name', 'u.last_name', 'u.email', 'u.status', DB::raw('c.status as company_status'))
            ->where('u.username', $request->input('username'))
            ->where('u.status', 1);

        if ($requestedAccessSlug !== '') {
            $userQuery
                ->join('appcfg.company_access_links as cal', 'cal.company_id', '=', 'u.company_id')
                ->whereRaw('LOWER(cal.access_slug) = ?', [$requestedAccessSlug])
                ->where('cal.is_active', 1);
        }

        $user = $userQuery->first();

        if (!$user || !Hash::check($request->input('password'), $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if ((int) ($user->company_status ?? 0) !== 1) {
            return response()->json([
                'message' => 'No fue posible iniciar sesion en este momento. Intenta nuevamente o contacta a soporte.',
            ], 401);
        }

        if ($this->isAdminPortalDevice($deviceId) && !$this->isAdminPortalUser((int) $user->id)) {
            return response()->json([
                'message' => 'No fue posible iniciar sesion en este momento. Intenta nuevamente o contacta a soporte.',
            ], 401);
        }

        $roleContext = $this->resolvePrimaryRoleContext((int) $user->id, (int) $user->company_id);

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

        $sessionId = DB::table('auth.refresh_tokens')->insertGetId($this->buildRefreshTokenInsertPayload(
            (int) $user->id,
            $refreshTokenHash,
            $refreshExpiresAt,
            $deviceId,
            $request->input('device_name')
        ));

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
                'permissions' => $this->resolveUserPermissions((int) $user->id, (int) $user->company_id),
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
            ->join('core.companies as c', 'c.id', '=', 'u.company_id')
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
                DB::raw('c.status as company_status'),
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

        if ((int) ($session->company_status ?? 0) !== 1) {
            return response()->json([
                'message' => 'Sesion no disponible temporalmente. Inicia sesion nuevamente.',
            ], 401);
        }

        if ($this->isAdminPortalDevice($deviceId) && !$this->isAdminPortalUser((int) $session->user_id)) {
            return response()->json([
                'message' => 'Sesion no disponible temporalmente. Inicia sesion nuevamente.',
            ], 401);
        }

        $roleContext = $this->resolvePrimaryRoleContext((int) $session->user_id, (int) $session->company_id);

        $newRefreshToken = ApiToken::makeRefreshToken();
        $newRefreshHash = ApiToken::hashRefreshToken($newRefreshToken, $deviceId);
        $refreshExpiresAt = now()->addDays((int) env('REFRESH_TOKEN_TTL_DAYS', 30));
        $accessTtlMinutes = (int) env('ACCESS_TOKEN_TTL_MINUTES', 30);

        $newSessionId = null;

        DB::transaction(function () use ($session, $newRefreshHash, $refreshExpiresAt, $deviceId, &$newSessionId) {
            DB::table('auth.refresh_tokens')
                ->where('id', $session->session_id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                ]);

            $newSessionId = DB::table('auth.refresh_tokens')->insertGetId($this->buildRefreshTokenInsertPayload(
                (int) $session->user_id,
                $newRefreshHash,
                $refreshExpiresAt,
                $deviceId,
                null
            ));
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
                'permissions' => $this->resolveUserPermissions((int) $session->user_id, (int) $session->company_id),
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
                'permissions' => $this->resolveUserPermissions((int) $user->id, (int) $user->company_id),
            ],
        ]);
    }

    private function resolveUserPermissions(int $userId, int $companyId): array
    {
        $modules = DB::table('appcfg.modules')
            ->where('status', 1)
            ->pluck('id', 'code');

        if ($modules->isEmpty()) {
            return [];
        }

        $roleAccess = DB::table('auth.role_module_access as rma')
            ->join('auth.user_roles as ur', 'ur.role_id', '=', 'rma.role_id')
            ->where('ur.user_id', $userId)
            ->whereIn('rma.module_id', $modules->values())
            ->selectRaw('rma.module_id')
            ->selectRaw('COALESCE(bool_or(rma.can_view), false) as can_view')
            ->selectRaw('COALESCE(bool_or(rma.can_create), false) as can_create')
            ->selectRaw('COALESCE(bool_or(rma.can_update), false) as can_update')
            ->selectRaw('COALESCE(bool_or(rma.can_delete), false) as can_delete')
            ->selectRaw('COALESCE(bool_or(rma.can_export), false) as can_export')
            ->selectRaw('COALESCE(bool_or(rma.can_approve), false) as can_approve')
            ->groupBy('rma.module_id')
            ->get()
            ->keyBy('module_id');

        $overrides = DB::table('auth.user_module_overrides')
            ->where('user_id', $userId)
            ->whereIn('module_id', $modules->values())
            ->get()
            ->keyBy('module_id');

        $columns = ['can_view', 'can_create', 'can_update', 'can_delete', 'can_export', 'can_approve'];
        $permissions = [];

        foreach ($modules as $code => $moduleId) {
            $role     = $roleAccess->get($moduleId);
            $override = $overrides->get($moduleId);
            $perm     = [];

            foreach ($columns as $col) {
                if ($override && $override->{$col} !== null) {
                    $perm[$col] = (bool) $override->{$col};
                } elseif ($role && $role->{$col} !== null) {
                    $perm[$col] = (bool) $role->{$col};
                } else {
                    $perm[$col] = false;
                }
            }

            $permissions[$code] = $perm;
        }

        return $permissions;
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

    private function ensureAdminPortalUsersTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.admin_portal_users (
                user_id BIGINT PRIMARY KEY,
                status SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )'
        );
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    private function buildRefreshTokenInsertPayload(int $userId, string $tokenHash, $expiresAt, string $deviceId, ?string $deviceName): array
    {
        $payload = [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ];

        if ($this->columnExists('auth', 'refresh_tokens', 'device_id')) {
            $payload['device_id'] = $deviceId;
        }

        if ($this->columnExists('auth', 'refresh_tokens', 'device_name')) {
            $payload['device_name'] = $deviceName !== null ? trim((string) $deviceName) : null;
        }

        return $payload;
    }

    private function isAdminPortalDevice(string $deviceId): bool
    {
        return strtoupper(trim($deviceId)) === 'ADMIN-PORTAL';
    }

    private function isAdminPortalUser(int $userId): bool
    {
        $this->ensureAdminPortalUsersTable();

        return DB::table('appcfg.admin_portal_users')
            ->where('user_id', $userId)
            ->where('status', 1)
            ->exists();
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
