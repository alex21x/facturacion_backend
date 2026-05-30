<?php

namespace App\Infrastructure\Repositories\Auth;

use App\Application\DTOs\Auth\AuthAuthenticatedSessionDTO;
use App\Application\DTOs\Auth\AuthAuthenticatedUserDTO;
use App\Application\DTOs\Auth\AuthLoginUserDTO;
use App\Application\DTOs\Auth\AuthRefreshSessionDTO;
use App\Application\DTOs\Auth\AuthRefreshSessionRecordDTO;
use App\Application\DTOs\Auth\AuthRoleContextDTO;
use App\Domain\Auth\Repositories\AuthSessionRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuthSessionRepository implements AuthSessionRepositoryInterface
{
    private static array $tableExistsCache = [];
    private static array $columnExistsCache = [];

    public function tableExists(string $schema, string $table): bool
    {
        $cacheKey = strtolower($schema . '.' . $table);
        if (array_key_exists($cacheKey, self::$tableExistsCache)) {
            return self::$tableExistsCache[$cacheKey];
        }

        $exists = DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();

        self::$tableExistsCache[$cacheKey] = (bool) $exists;

        return self::$tableExistsCache[$cacheKey];
    }

    public function columnExists(string $schema, string $table, string $column): bool
    {
        $cacheKey = strtolower($schema . '.' . $table . '.' . $column);
        if (array_key_exists($cacheKey, self::$columnExistsCache)) {
            return self::$columnExistsCache[$cacheKey];
        }

        $exists = DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();

        self::$columnExistsCache[$cacheKey] = (bool) $exists;

        return self::$columnExistsCache[$cacheKey];
    }

    public function findActiveUserForLogin(string $username, ?string $accessSlug): ?AuthLoginUserDTO
    {
        $query = DB::table('auth.users as u')
            ->join('core.companies as c', 'c.id', '=', 'u.company_id')
            ->select(
                'u.id',
                'u.company_id',
                'u.branch_id',
                'u.username',
                'u.password_hash',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.status',
                DB::raw('c.status as company_status')
            )
            ->where('u.username', $username)
            ->where('u.status', 1);

        if ($accessSlug !== null && $accessSlug !== '') {
            $query
                ->join('appcfg.company_access_links as cal', 'cal.company_id', '=', 'u.company_id')
                ->whereRaw('LOWER(cal.access_slug) = ?', [strtolower($accessSlug)])
                ->where('cal.is_active', 1);
        }

        $user = $query->first();

        return $user ? AuthLoginUserDTO::fromRow($user) : null;
    }

    public function revokeActiveRefreshTokensForDevice(int $userId, string $deviceHashPrefix): void
    {
        DB::table('auth.refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('token_hash', 'like', $deviceHashPrefix)
            ->update([
                'revoked_at' => now(),
            ]);
    }

    public function insertRefreshToken(array $payload): int
    {
        return (int) DB::table('auth.refresh_tokens')->insertGetId($payload);
    }

    public function touchUserLastLogin(int $userId): void
    {
        DB::table('auth.users')
            ->where('id', $userId)
            ->update([
                'last_login_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function findValidRefreshSession(string $refreshTokenHash): ?AuthRefreshSessionDTO
    {
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

        return $session ? AuthRefreshSessionDTO::fromRow($session) : null;
    }

    public function findRefreshSessionById(int $sessionId): ?AuthRefreshSessionRecordDTO
    {
        $session = DB::table('auth.refresh_tokens as rt')
            ->select([
                'rt.id',
                'rt.user_id',
                'rt.expires_at',
                'rt.revoked_at',
            ])
            ->where('rt.id', $sessionId)
            ->first();

            return $session ? AuthRefreshSessionRecordDTO::fromRow($session) : null;
    }

            public function findAuthenticatedSessionByClaims(int $sessionId, int $userId): ?AuthAuthenticatedSessionDTO
    {
                $hasDeviceId = $this->columnExists('auth', 'refresh_tokens', 'device_id');
                $hasDeviceName = $this->columnExists('auth', 'refresh_tokens', 'device_name');

        $query = DB::table('auth.refresh_tokens as rt')
            ->where('rt.id', $sessionId)
            ->where('rt.user_id', $userId)
            ->whereNull('rt.revoked_at')
            ->where('rt.expires_at', '>', now())
            ->select([
                'rt.id',
                'rt.user_id',
                'rt.expires_at',
                'rt.revoked_at',
            ]);

        if ($hasDeviceId) {
            $query->addSelect('rt.device_id');
        }

        if ($hasDeviceName) {
            $query->addSelect('rt.device_name');
        }

        $session = $query->first();

        return $session ? AuthAuthenticatedSessionDTO::fromRow($session) : null;
    }

    public function revokeRefreshSession(int $sessionId): void
    {
        DB::table('auth.refresh_tokens')
            ->where('id', $sessionId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);
    }

    public function findAuthenticatedUserById(int $userId): ?AuthAuthenticatedUserDTO
    {
        $user = DB::table('auth.users as u')
            ->join('core.companies as c', 'c.id', '=', 'u.company_id')
            ->select(
                'u.id',
                'u.company_id',
                'u.branch_id',
                'u.preferred_warehouse_id',
                'u.preferred_cash_register_id',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.status'
            )
            ->where('u.id', $userId)
            ->where('u.status', 1)
            ->where('c.status', 1)
            ->first();

            return $user ? AuthAuthenticatedUserDTO::fromRow($user) : null;
    }

    public function rotateRefreshSession(
        int $sessionId,
        int $userId,
        string $newRefreshHash,
        $refreshExpiresAt,
        string $deviceId,
        ?string $deviceName
    ): int {
        $hasDeviceId = $this->columnExists('auth', 'refresh_tokens', 'device_id');
        $hasDeviceName = $this->columnExists('auth', 'refresh_tokens', 'device_name');

        return (int) DB::transaction(function () use ($sessionId, $userId, $newRefreshHash, $refreshExpiresAt, $deviceId, $deviceName, $hasDeviceId, $hasDeviceName) {
            $this->revokeRefreshSession($sessionId);

            $payload = [
                'user_id' => $userId,
                'token_hash' => $newRefreshHash,
                'expires_at' => $refreshExpiresAt,
                'created_at' => now(),
            ];

            if ($hasDeviceId) {
                $payload['device_id'] = $deviceId;
            }

            if ($hasDeviceName) {
                $payload['device_name'] = $deviceName;
            }

            return $this->insertRefreshToken($payload);
        });
    }

    public function listActiveModulesByCode(): Collection
    {
        return DB::table('appcfg.modules')
            ->where('status', 1)
            ->pluck('id', 'code');
    }

    public function listRoleAccessByUserAndModuleIds(int $userId, array $moduleIds): Collection
    {
        return DB::table('auth.role_module_access as rma')
            ->join('auth.user_roles as ur', 'ur.role_id', '=', 'rma.role_id')
            ->where('ur.user_id', $userId)
            ->whereIn('rma.module_id', $moduleIds)
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
    }

    public function listUserModuleOverridesByUserAndModuleIds(int $userId, array $moduleIds): Collection
    {
        return DB::table('auth.user_module_overrides')
            ->where('user_id', $userId)
            ->whereIn('module_id', $moduleIds)
            ->get()
            ->keyBy('module_id');
    }

    public function ensureCompanyRoleProfilesTable(): void
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

    public function resolvePrimaryRoleContext(int $userId, int $companyId): ?AuthRoleContextDTO
    {
        $hasCompanyRoleProfiles = $this->tableExists('appcfg', 'company_role_profiles');

        $query = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('r.company_id', $companyId)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code');

        if ($hasCompanyRoleProfiles) {
            $query->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($companyId): void {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', $companyId);
            });
            $query->addSelect('crp.functional_profile as role_profile');
        } else {
            $query->selectRaw('NULL::varchar as role_profile');
        }

        $roleContext = $query->first();

            return $roleContext ? AuthRoleContextDTO::fromRow($roleContext) : null;
    }

    public function listActiveRefreshSessionIdsForDevice(int $userId, string $deviceHashPrefix): array
    {
        return DB::table('auth.refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('token_hash', 'like', $deviceHashPrefix)
            ->pluck('id')
            ->map(static fn ($value) => (int) $value)
            ->all();
    }

    public function ensureAdminPortalUsersTable(): void
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

    public function isAdminPortalUser(int $userId): bool
    {
        if (!$this->tableExists('appcfg', 'admin_portal_users')) {
            return false;
        }

        return DB::table('appcfg.admin_portal_users')
            ->where('user_id', $userId)
            ->where('status', 1)
            ->exists();
    }
}
