<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Services\Auth\AuthSessionService;
use App\Support\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private AuthSessionService $authSessionService
    ) {
    }

    public function login(LoginRequest $request)
    {
        $deviceId = trim((string) $request->input('device_id'));
        $requestedAccessSlug = strtolower(trim((string) $request->input('company_access_slug', '')));

        if (!$this->authSessionService->canUseRequestedAccessSlug($requestedAccessSlug)) {
            return response()->json([
                'message' => 'No fue posible iniciar sesion en este momento. Intenta nuevamente o contacta a soporte.',
            ], 401);
        }

        $user = $this->authSessionService->findActiveUserForLogin(
            (string) $request->input('username'),
            $requestedAccessSlug
        );

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
        $accessTtlMinutes = $this->resolveAccessTtlMinutes();

        // Revoke active refresh tokens for this user+device.
        $deviceHashPrefix = ApiToken::deviceHash($deviceId) . '.%';

        $this->authSessionService->revokeActiveRefreshTokensForDevice((int) $user->id, $deviceHashPrefix);

        $sessionId = $this->authSessionService->createRefreshSession(
            (int) $user->id,
            $refreshTokenHash,
            $refreshExpiresAt,
            $deviceId,
            $request->input('device_name')
        );

        $accessToken = ApiToken::makeAccessToken([
            'uid' => (int) $user->id,
            'cid' => (int) $user->company_id,
            'bid' => $user->branch_id !== null ? (int) $user->branch_id : null,
            'sid' => (int) $sessionId,
            'did' => ApiToken::deviceHash($deviceId),
        ], $accessTtlMinutes);

        $accessExpiresAt = now()->addMinutes($accessTtlMinutes);

        $this->authSessionService->touchUserLastLogin((int) $user->id);

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

    public function refresh(RefreshRequest $request)
    {
        $deviceId = trim((string) $request->input('device_id'));
        $refreshToken = trim((string) $request->input('refresh_token'));
        $refreshTokenHash = ApiToken::hashRefreshToken($refreshToken, $deviceId);

        $session = $this->authSessionService->findValidRefreshSession($refreshTokenHash);

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
        $accessTtlMinutes = $this->resolveAccessTtlMinutes();

        $newSessionId = $this->authSessionService->rotateRefreshSession(
            (int) $session->session_id,
            (int) $session->user_id,
            $newRefreshHash,
            $refreshExpiresAt,
            $deviceId,
            null
        );

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
        return $this->authSessionService->resolveUserPermissions($userId);
    }

    private function resolveAccessTtlMinutes(): int
    {
        $configured = (int) env('ACCESS_TOKEN_TTL_MINUTES', 240);

        // Guarantee at least 4 hours to avoid overly short sessions in operations.
        return max(240, $configured);
    }

    private function resolvePrimaryRoleContext(int $userId, int $companyId): array
    {
        return $this->authSessionService->resolvePrimaryRoleContext($userId, $companyId);
    }

    private function isAdminPortalDevice(string $deviceId): bool
    {
        return strtoupper(trim($deviceId)) === 'ADMIN-PORTAL';
    }

    private function isAdminPortalUser(int $userId): bool
    {
        return $this->authSessionService->isAdminPortalUser($userId);
    }

    public function logout(Request $request)
    {
        $sessionId = $request->attributes->get('auth_session_id');

        if ($sessionId) {
            $this->authSessionService->revokeRefreshSession((int) $sessionId);
        }

        return response()->json([
            'message' => 'Logged out',
        ]);
    }
}
