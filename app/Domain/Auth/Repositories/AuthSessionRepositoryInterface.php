<?php

namespace App\Domain\Auth\Repositories;

use App\Application\DTOs\Auth\AuthAuthenticatedSessionDTO;
use App\Application\DTOs\Auth\AuthAuthenticatedUserDTO;
use App\Application\DTOs\Auth\AuthLoginUserDTO;
use App\Application\DTOs\Auth\AuthRefreshSessionDTO;
use App\Application\DTOs\Auth\AuthRefreshSessionRecordDTO;
use App\Application\DTOs\Auth\AuthRoleContextDTO;
use Illuminate\Support\Collection;

interface AuthSessionRepositoryInterface
{
    public function tableExists(string $schema, string $table): bool;

    public function columnExists(string $schema, string $table, string $column): bool;

    public function findActiveUserForLogin(string $username, ?string $accessSlug): ?AuthLoginUserDTO;

    public function revokeActiveRefreshTokensForDevice(int $userId, string $deviceHashPrefix): void;

    public function insertRefreshToken(array $payload): int;

    public function touchUserLastLogin(int $userId): void;

    public function findValidRefreshSession(string $refreshTokenHash): ?AuthRefreshSessionDTO;

    public function findRefreshSessionById(int $sessionId): ?AuthRefreshSessionRecordDTO;

    public function findAuthenticatedSessionByClaims(int $sessionId, int $userId): ?AuthAuthenticatedSessionDTO;

    public function revokeRefreshSession(int $sessionId): void;

    public function rotateRefreshSession(
        int $sessionId,
        int $userId,
        string $newRefreshHash,
        $refreshExpiresAt,
        string $deviceId,
        ?string $deviceName
    ): int;

    public function listActiveModulesByCode(): Collection;

    public function listRoleAccessByUserAndModuleIds(int $userId, array $moduleIds): Collection;

    public function listUserModuleOverridesByUserAndModuleIds(int $userId, array $moduleIds): Collection;

    public function ensureCompanyRoleProfilesTable(): void;

    public function resolvePrimaryRoleContext(int $userId, int $companyId): ?AuthRoleContextDTO;

    public function findAuthenticatedUserById(int $userId): ?AuthAuthenticatedUserDTO;

    public function listActiveRefreshSessionIdsForDevice(int $userId, string $deviceHashPrefix): array;

    public function ensureAdminPortalUsersTable(): void;

    public function isAdminPortalUser(int $userId): bool;
}
