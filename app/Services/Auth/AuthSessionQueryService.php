<?php

namespace App\Services\Auth;

use App\Application\DTOs\Auth\AuthAuthenticatedUserDTO;
use App\Application\DTOs\Auth\AuthLoginUserDTO;
use App\Application\DTOs\Auth\AuthRefreshSessionDTO;
use App\Domain\Auth\Repositories\AuthSessionRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class AuthSessionQueryService
{
    private const AUTH_SESSION_CACHE_TTL_MINUTES = 5;
    private const AUTH_PERMISSIONS_CACHE_TTL_SECONDS = 180;
    private const AUTH_ROLE_CONTEXT_CACHE_TTL_SECONDS = 180;
    private const AUTH_ADMIN_PORTAL_CACHE_TTL_SECONDS = 180;

    private ?bool $companyRoleProfilesTableEnsured = null;
    private ?bool $adminPortalUsersTableEnsured = null;
    private ?bool $preferRedisCache = null;

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

    public function findActiveUserForLogin(string $username, string $requestedAccessSlug): ?AuthLoginUserDTO
    {
        return $this->repository->findActiveUserForLogin(
            $username,
            $requestedAccessSlug !== '' ? $requestedAccessSlug : null
        );
    }

    public function findValidRefreshSession(string $refreshTokenHash): ?AuthRefreshSessionDTO
    {
        return $this->repository->findValidRefreshSession($refreshTokenHash);
    }

    public function resolveAuthenticatedRequestContext(int $userId, int $sessionId): ?array
    {
        $cacheKey = $this->authenticatedSessionCacheKey($userId, $sessionId);
        $cached = $this->getCachedSessionContext($cacheKey);
        if (is_array($cached) && isset($cached['user'], $cached['session'])) {
            return $cached;
        }

        $session = $this->repository->findAuthenticatedSessionByClaims($sessionId, $userId);
        if (!$session) {
            return null;
        }

        $user = $this->repository->findAuthenticatedUserById($userId);
        if (!$user) {
            return null;
        }

        $roleContext = $this->resolvePrimaryRoleContext($userId, (int) $user->company_id);
        $userWithRoleContext = $user->withRoleContext($roleContext['role_code'], $roleContext['role_profile']);

        $context = [
            'user' => $userWithRoleContext,
            'session' => $session,
        ];

        $this->putCachedSessionContext($cacheKey, $context);

        return $context;
    }

    public function resolveUserPermissions(int $userId): array
    {
        $cacheKey = 'auth:permissions:' . $userId;

        return $this->rememberCache($cacheKey, self::AUTH_PERMISSIONS_CACHE_TTL_SECONDS, function () use ($userId) {
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
        });
    }

    public function resolvePrimaryRoleContext(int $userId, int $companyId): array
    {
        $cacheKey = 'auth:role-context:' . $companyId . ':' . $userId;

        return $this->rememberCache($cacheKey, self::AUTH_ROLE_CONTEXT_CACHE_TTL_SECONDS, function () use ($userId, $companyId) {
            $this->ensureCompanyRoleProfilesTable();
            $row = $this->repository->resolvePrimaryRoleContext($userId, $companyId);

            return [
                'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
                'role_profile' => $row && $row->role_profile !== null ? (string) $row->role_profile : null,
            ];
        });
    }

    public function isAdminPortalUser(int $userId): bool
    {
        $cacheKey = 'auth:admin-portal-user:' . $userId;

        return (bool) $this->rememberCache($cacheKey, self::AUTH_ADMIN_PORTAL_CACHE_TTL_SECONDS, function () use ($userId) {
            $this->ensureAdminPortalUsersTable();
            return $this->repository->isAdminPortalUser($userId);
        });
    }

    private function authenticatedSessionCacheKey(int $userId, int $sessionId): string
    {
        return 'auth:session-context:' . $userId . ':' . $sessionId;
    }

    private function getCachedSessionContext(string $cacheKey): mixed
    {
        if (!$this->shouldUseRedisCache()) {
            return Cache::get($cacheKey);
        }

        try {
            return Cache::store('redis')->get($cacheKey);
        } catch (\Throwable $e) {
            return Cache::get($cacheKey);
        }
    }

    private function putCachedSessionContext(string $cacheKey, array $context): void
    {
        $ttl = now()->addMinutes(self::AUTH_SESSION_CACHE_TTL_MINUTES);

        if (!$this->shouldUseRedisCache()) {
            Cache::put($cacheKey, $context, $ttl);
            return;
        }

        try {
            Cache::store('redis')->put($cacheKey, $context, $ttl);
        } catch (\Throwable $e) {
            Cache::put($cacheKey, $context, $ttl);
        }
    }

    private function rememberCache(string $cacheKey, int $ttlSeconds, callable $callback): mixed
    {
        $ttl = now()->addSeconds($ttlSeconds);

        if (!$this->shouldUseRedisCache()) {
            return Cache::remember($cacheKey, $ttl, $callback);
        }

        try {
            return Cache::store('redis')->remember($cacheKey, $ttl, $callback);
        } catch (\Throwable $e) {
            return Cache::remember($cacheKey, $ttl, $callback);
        }
    }

    private function shouldUseRedisCache(): bool
    {
        if ($this->preferRedisCache !== null) {
            return $this->preferRedisCache;
        }

        $override = strtolower(trim((string) env('AUTH_CACHE_USE_REDIS', 'auto')));
        if (in_array($override, ['1', 'true', 'yes', 'on'], true)) {
            return $this->preferRedisCache = true;
        }

        if (in_array($override, ['0', 'false', 'no', 'off'], true)) {
            return $this->preferRedisCache = false;
        }

        $defaultStore = strtolower(trim((string) config('cache.default', '')));
        return $this->preferRedisCache = ($defaultStore === 'redis');
    }

    private function ensureCompanyRoleProfilesTable(): void
    {
        if ($this->companyRoleProfilesTableEnsured === true) {
            return;
        }

        $allowRuntimeEnsure = strtolower((string) env('AUTH_RUNTIME_SCHEMA_ENSURE', 'false'));
        if (in_array($allowRuntimeEnsure, ['1', 'true', 'yes', 'on'], true)) {
            $this->repository->ensureCompanyRoleProfilesTable();
        }

        $this->companyRoleProfilesTableEnsured = true;
    }

    private function ensureAdminPortalUsersTable(): void
    {
        if ($this->adminPortalUsersTableEnsured === true) {
            return;
        }

        $allowRuntimeEnsure = strtolower((string) env('AUTH_RUNTIME_SCHEMA_ENSURE', 'false'));
        if (in_array($allowRuntimeEnsure, ['1', 'true', 'yes', 'on'], true)) {
            $this->repository->ensureAdminPortalUsersTable();
        }

        $this->adminPortalUsersTableEnsured = true;
    }
}
