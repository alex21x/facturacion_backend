<?php

namespace App\Domain\Auth\Repositories;

use Illuminate\Support\Collection;

interface AuthSessionRepositoryInterface
{
    public function tableExists(string $schema, string $table): bool;

    public function columnExists(string $schema, string $table, string $column): bool;

    public function findActiveUserForLogin(string $username, ?string $accessSlug): ?object;

    public function revokeActiveRefreshTokensForDevice(int $userId, string $deviceHashPrefix): void;

    public function insertRefreshToken(array $payload): int;

    public function touchUserLastLogin(int $userId): void;

    public function findValidRefreshSession(string $refreshTokenHash): ?object;

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

    public function resolvePrimaryRoleContext(int $userId, int $companyId): ?object;

    public function ensureAdminPortalUsersTable(): void;

    public function isAdminPortalUser(int $userId): bool;
}
