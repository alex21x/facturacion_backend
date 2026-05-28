<?php

namespace App\Services\Auth;

use App\Domain\Auth\Repositories\AuthSessionRepositoryInterface;

class AuthSessionService
{
    public function __construct(
        private AuthSessionRepositoryInterface $repository
    ) {
    }

    public function canUseRequestedAccessSlug(string $requestedAccessSlug): bool
    {
        if ($requestedAccessSlug === '') {
            return true;
        }

        return $this->repository->tableExists('appcfg', 'company_access_links');
    }

    public function findActiveUserForLogin(string $username, string $requestedAccessSlug): ?object
    {
        return $this->repository->findActiveUserForLogin(
            $username,
            $requestedAccessSlug !== '' ? $requestedAccessSlug : null
        );
    }

    public function revokeActiveRefreshTokensForDevice(int $userId, string $deviceHashPrefix): void
    {
        $this->repository->revokeActiveRefreshTokensForDevice($userId, $deviceHashPrefix);
    }

    public function createRefreshSession(
        int $userId,
        string $tokenHash,
        $expiresAt,
        string $deviceId,
        ?string $deviceName
    ): int {
        return $this->repository->insertRefreshToken(
            $this->buildRefreshTokenInsertPayload($userId, $tokenHash, $expiresAt, $deviceId, $deviceName)
        );
    }

    public function touchUserLastLogin(int $userId): void
    {
        $this->repository->touchUserLastLogin($userId);
    }

    public function findValidRefreshSession(string $refreshTokenHash): ?object
    {
        return $this->repository->findValidRefreshSession($refreshTokenHash);
    }

    public function rotateRefreshSession(
        int $sessionId,
        int $userId,
        string $newRefreshHash,
        $refreshExpiresAt,
        string $deviceId,
        ?string $deviceName
    ): int {
        return $this->repository->rotateRefreshSession(
            $sessionId,
            $userId,
            $newRefreshHash,
            $refreshExpiresAt,
            $deviceId,
            $deviceName
        );
    }

    public function resolveUserPermissions(int $userId): array
    {
        $modules = $this->repository->listActiveModulesByCode();
        if ($modules->isEmpty()) {
            return [];
        }

        $moduleIds = $modules->values()->all();
        $roleAccess = $this->repository->listRoleAccessByUserAndModuleIds($userId, $moduleIds);
        $overrides = $this->repository->listUserModuleOverridesByUserAndModuleIds($userId, $moduleIds);

        $columns = ['can_view', 'can_create', 'can_update', 'can_delete', 'can_export', 'can_approve'];
        $permissions = [];

        foreach ($modules as $code => $moduleId) {
            $role = $roleAccess->get($moduleId);
            $override = $overrides->get($moduleId);
            $perm = [];

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

    public function resolvePrimaryRoleContext(int $userId, int $companyId): array
    {
        $this->repository->ensureCompanyRoleProfilesTable();
        $row = $this->repository->resolvePrimaryRoleContext($userId, $companyId);

        return [
            'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
            'role_profile' => $row && $row->role_profile !== null ? (string) $row->role_profile : null,
        ];
    }

    public function isAdminPortalUser(int $userId): bool
    {
        $this->repository->ensureAdminPortalUsersTable();
        return $this->repository->isAdminPortalUser($userId);
    }

    public function revokeRefreshSession(int $sessionId): void
    {
        $this->repository->revokeRefreshSession($sessionId);
    }

    private function buildRefreshTokenInsertPayload(
        int $userId,
        string $tokenHash,
        $expiresAt,
        string $deviceId,
        ?string $deviceName
    ): array {
        $payload = [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ];

        if ($this->repository->columnExists('auth', 'refresh_tokens', 'device_id')) {
            $payload['device_id'] = $deviceId;
        }

        if ($this->repository->columnExists('auth', 'refresh_tokens', 'device_name')) {
            $payload['device_name'] = $deviceName !== null ? trim((string) $deviceName) : null;
        }

        return $payload;
    }
}
