<?php

namespace App\Services\Auth;

use App\Application\DTOs\Auth\AuthLoginUserDTO;
use App\Application\DTOs\Auth\AuthRefreshSessionDTO;

class AuthSessionService
{
    public function __construct(
        private AuthSessionQueryService $queryService,
        private AuthSessionCommandService $commandService
    ) {
    }

    public function canUseRequestedAccessSlug(string $requestedAccessSlug): bool
    {
        return $this->queryService->canUseRequestedAccessSlug($requestedAccessSlug);
    }

    public function findActiveUserForLogin(string $username, string $requestedAccessSlug): ?AuthLoginUserDTO
    {
        return $this->queryService->findActiveUserForLogin($username, $requestedAccessSlug);
    }

    public function revokeActiveRefreshTokensForDevice(int $userId, string $deviceHashPrefix): void
    {
        $this->commandService->revokeActiveRefreshTokensForDevice($userId, $deviceHashPrefix);
    }

    public function createRefreshSession(
        int $userId,
        string $tokenHash,
        $expiresAt,
        string $deviceId,
        ?string $deviceName
    ): int {
        return $this->commandService->createRefreshSession($userId, $tokenHash, $expiresAt, $deviceId, $deviceName);
    }

    public function touchUserLastLogin(int $userId): void
    {
        $this->commandService->touchUserLastLogin($userId);
    }

    public function findValidRefreshSession(string $refreshTokenHash): ?AuthRefreshSessionDTO
    {
        return $this->queryService->findValidRefreshSession($refreshTokenHash);
    }

    public function resolveAuthenticatedRequestContext(int $userId, int $sessionId): ?array
    {
        return $this->queryService->resolveAuthenticatedRequestContext($userId, $sessionId);
    }

    public function rotateRefreshSession(
        int $sessionId,
        int $userId,
        string $newRefreshHash,
        $refreshExpiresAt,
        string $deviceId,
        ?string $deviceName
    ): int {
        return $this->commandService->rotateRefreshSession($sessionId, $userId, $newRefreshHash, $refreshExpiresAt, $deviceId, $deviceName);
    }

    public function resolveUserPermissions(int $userId): array
    {
        return $this->queryService->resolveUserPermissions($userId);
    }

    public function resolvePrimaryRoleContext(int $userId, int $companyId): array
    {
        return $this->queryService->resolvePrimaryRoleContext($userId, $companyId);
    }

    public function isAdminPortalUser(int $userId): bool
    {
        return $this->queryService->isAdminPortalUser($userId);
    }

    public function revokeRefreshSession(int $sessionId): void
    {
        $this->commandService->revokeRefreshSession($sessionId);
    }
}
