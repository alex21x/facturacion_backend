<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CompanyRateLimitRepository
{
    private const SCHEMA_CACHE_TTL_SECONDS = 900;

    private static array $tableExistsCache = [];
    private static array $columnsCache = [];

    public function tableExists(string $schema, string $table): bool
    {
        $cacheKey = strtolower($schema . '.' . $table);
        if (array_key_exists($cacheKey, self::$tableExistsCache)) {
            return self::$tableExistsCache[$cacheKey];
        }

        $cacheStoreKey = 'appcfg:company_rate_limit:table_exists:' . $cacheKey;
        $exists = (bool) Cache::remember($cacheStoreKey, self::SCHEMA_CACHE_TTL_SECONDS, function () use ($schema, $table): bool {
            return DB::table('information_schema.tables')
                ->where('table_schema', $schema)
                ->where('table_name', $table)
                ->exists();
        });

        self::$tableExistsCache[$cacheKey] = $exists;

        return $exists;
    }

    public function hasColumns(string $schema, string $table, array $columns): bool
    {
        if ($columns === []) {
            return true;
        }

        $normalizedColumns = array_values(array_unique(array_map(static fn ($value) => strtolower((string) $value), $columns)));
        sort($normalizedColumns);

        $cacheKey = strtolower($schema . '.' . $table . ':' . implode(',', $normalizedColumns));
        if (array_key_exists($cacheKey, self::$columnsCache)) {
            return self::$columnsCache[$cacheKey];
        }

        $cacheStoreKey = 'appcfg:company_rate_limit:has_columns:' . $cacheKey;
        $hasAllColumns = (bool) Cache::remember($cacheStoreKey, self::SCHEMA_CACHE_TTL_SECONDS, function () use ($schema, $table, $normalizedColumns): bool {
            $found = DB::table('information_schema.columns')
                ->where('table_schema', $schema)
                ->where('table_name', $table)
                ->whereIn('column_name', $normalizedColumns)
                ->pluck('column_name')
                ->map(static fn ($value) => strtolower((string) $value))
                ->all();

            foreach ($normalizedColumns as $column) {
                if (!in_array($column, $found, true)) {
                    return false;
                }
            }

            return true;
        });

        self::$columnsCache[$cacheKey] = $hasAllColumns;

        return $hasAllColumns;
    }

    public function listNonSystemCompanies(int $systemCompanyId): Collection
    {
        return DB::table('core.companies')
            ->where('id', '!=', $systemCompanyId)
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);
    }

    public function getAllCompanyRateLimits(): Collection
    {
        return DB::table('appcfg.company_rate_limits')
            ->get([
                'company_id',
                'is_enabled',
                'requests_per_minute',
                'requests_per_minute_read',
                'requests_per_minute_write',
                'requests_per_minute_reports',
                'response_delay_enabled',
                'response_delay_ms',
                'plan_code',
                'last_preset_code',
                'updated_at',
            ]);
    }

    public function findCompanyRateLimitByCompanyId(int $companyId, bool $hasProfileColumns): ?object
    {
        $query = DB::table('appcfg.company_rate_limits')
            ->where('company_id', $companyId);

        if ($hasProfileColumns) {
            return $query
                ->select([
                    'requests_per_minute',
                    'requests_per_minute_read',
                    'requests_per_minute_write',
                    'requests_per_minute_reports',
                    'is_enabled',
                ])
                ->first();
        }

        return $query
            ->select([
                'requests_per_minute',
                'is_enabled',
            ])
            ->first();
    }

    public function findCompanyResponseDelaySettings(int $companyId): ?object
    {
        return DB::table('appcfg.company_rate_limits as limits')
            ->leftJoin('appcfg.company_subscriptions as subscriptions', 'subscriptions.company_id', '=', 'limits.company_id')
            ->where('limits.company_id', $companyId)
            ->first([
                'limits.response_delay_enabled',
                'limits.response_delay_ms',
                'subscriptions.billing_cycle',
                'subscriptions.status as subscription_status',
                'subscriptions.starts_at',
                'subscriptions.current_period_starts_at',
                'subscriptions.current_period_ends_at',
            ]);
    }

    public function companyExists(int $companyId): bool
    {
        return DB::table('core.companies')->where('id', $companyId)->exists();
    }

    public function existingCompanyIds(array $companyIds): array
    {
        return DB::table('core.companies')
            ->whereIn('id', $companyIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function upsertCompanyRateLimit(int $companyId, array $payload, ?int $updatedBy): void
    {
        $values = [
            'is_enabled' => (bool) $payload['is_enabled'],
            'requests_per_minute' => (int) $payload['requests_per_minute_read'],
            'requests_per_minute_read' => (int) $payload['requests_per_minute_read'],
            'requests_per_minute_write' => (int) $payload['requests_per_minute_write'],
            'requests_per_minute_reports' => (int) $payload['requests_per_minute_reports'],
            'plan_code' => (string) ($payload['plan_code'] ?? 'CUSTOM'),
            'last_preset_code' => $payload['preset_code'] ?? null,
            'updated_by' => $updatedBy,
            'updated_at' => now(),
            'created_at' => now(),
        ];
        $this->appendResponseDelayValues($values, $payload);

        DB::table('appcfg.company_rate_limits')->updateOrInsert(
            ['company_id' => $companyId],
            $values
        );

        $this->forgetCompanyRateLimitCache($companyId);
    }

    public function upsertCompanyRateLimitsBatch(array $companyIds, array $payload, ?int $updatedBy): void
    {
        $normalizedCompanyIds = array_values(array_unique(array_map(static fn ($id) => (int) $id, $companyIds)));
        if ($normalizedCompanyIds === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($normalizedCompanyIds as $companyId) {
            $row = [
                'company_id' => (int) $companyId,
                'is_enabled' => (bool) $payload['is_enabled'],
                'requests_per_minute' => (int) $payload['requests_per_minute_read'],
                'requests_per_minute_read' => (int) $payload['requests_per_minute_read'],
                'requests_per_minute_write' => (int) $payload['requests_per_minute_write'],
                'requests_per_minute_reports' => (int) $payload['requests_per_minute_reports'],
                'plan_code' => (string) ($payload['plan_code'] ?? 'CUSTOM'),
                'last_preset_code' => $payload['preset_code'] ?? null,
                'updated_by' => $updatedBy,
                'updated_at' => $now,
                'created_at' => $now,
            ];
            $this->appendResponseDelayValues($row, $payload);
            $rows[] = $row;
        }

        $updateColumns = [
            'is_enabled',
            'requests_per_minute',
            'requests_per_minute_read',
            'requests_per_minute_write',
            'requests_per_minute_reports',
            'plan_code',
            'last_preset_code',
            'updated_by',
            'updated_at',
        ];
        if (array_key_exists('response_delay_enabled', $payload)) {
            $updateColumns[] = 'response_delay_enabled';
        }
        if (array_key_exists('response_delay_ms', $payload)) {
            $updateColumns[] = 'response_delay_ms';
        }

        DB::table('appcfg.company_rate_limits')->upsert(
            $rows,
            ['company_id'],
            $updateColumns
        );

        foreach ($normalizedCompanyIds as $companyId) {
            $this->forgetCompanyRateLimitCache((int) $companyId);
        }
    }

    private function forgetCompanyRateLimitCache(int $companyId): void
    {
        foreach (['read', 'write', 'reports'] as $profile) {
            Cache::forget(sprintf('tenant_rate_limit_cfg:%d:%s', $companyId, $profile));
        }
        Cache::forget(sprintf('%s:%d', config('company_response_delay.cache_key_prefix'), $companyId));
    }

    private function appendResponseDelayValues(array &$values, array $payload): void
    {
        foreach (['response_delay_enabled', 'response_delay_ms'] as $key) {
            if (array_key_exists($key, $payload)) {
                $values[$key] = $payload[$key];
            }
        }
    }
}
