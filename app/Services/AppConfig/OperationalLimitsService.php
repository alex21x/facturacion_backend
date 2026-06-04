<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\OperationalLimitsRepository;
use Illuminate\Support\Facades\Cache;

class OperationalLimitsService
{
    private const TABLE_EXISTS_CACHE_TTL_SECONDS = 300;
    private const USAGE_CACHE_TTL_SECONDS = 5;
    private const PLATFORM_LIMITS_CACHE_TTL_SECONDS = 15;
    private const COMPANY_LIMITS_CACHE_TTL_SECONDS = 15;
    private const ACTIVE_VERTICAL_CACHE_TTL_SECONDS = 30;

    private array $usageCache = [];
    private array $tableExistsCache = [];
    private ?array $platformLimitsCache = null;
    private array $companyLimitsCache = [];

    public function __construct(
        private OperationalLimitsRepository $operationalLimitsRepository
    ) {
    }

    public function hasRequiredTables(): bool
    {
        return $this->tableExistsCached('appcfg', 'platform_limits')
            && $this->tableExistsCached('appcfg', 'company_operational_limits');
    }

    public function getUsage(int $companyId): array
    {
        if (array_key_exists($companyId, $this->usageCache)) {
            return $this->usageCache[$companyId];
        }

        $usage = Cache::remember(
            $this->usageSharedCacheKey($companyId),
            self::USAGE_CACHE_TTL_SECONDS,
            function () use ($companyId) {
                return [
                    'enabled_companies' => $this->operationalLimitsRepository->countEnabledCompanies(),
                    'enabled_branches' => $this->operationalLimitsRepository->countEnabledBranches($companyId),
                    'enabled_warehouses' => $this->operationalLimitsRepository->countEnabledWarehouses($companyId),
                    'enabled_cash_registers' => $this->operationalLimitsRepository->countEnabledCashRegisters($companyId),
                ];
            }
        );

        $this->usageCache[$companyId] = $usage;

        return $usage;
    }

    public function getPlatformLimits(): array
    {
        if ($this->platformLimitsCache !== null) {
            return $this->platformLimitsCache;
        }

        $this->platformLimitsCache = Cache::remember(
            'operational_limits:platform:v1',
            self::PLATFORM_LIMITS_CACHE_TTL_SECONDS,
            function () {
                $enabledCompanies = $this->operationalLimitsRepository->countEnabledCompanies();

                if (!$this->tableExistsCached('appcfg', 'platform_limits')) {
                    return [
                        'max_companies_enabled' => max(1, $enabledCompanies),
                    ];
                }

                $row = $this->operationalLimitsRepository->findPlatformLimitsRow();

                return [
                    'max_companies_enabled' => $row ? (int) $row->max_companies_enabled : max(1, $enabledCompanies),
                ];
            }
        );

        return $this->platformLimitsCache;
    }

    public function getCompanyLimits(int $companyId): array
    {
        if (array_key_exists($companyId, $this->companyLimitsCache)) {
            return $this->companyLimitsCache[$companyId];
        }

        $limits = Cache::remember(
            $this->companyLimitsSharedCacheKey($companyId),
            self::COMPANY_LIMITS_CACHE_TTL_SECONDS,
            function () use ($companyId) {
                $usage = $this->getUsage($companyId);

                if (!$this->tableExistsCached('appcfg', 'company_operational_limits')) {
                    return $this->fallbackCompanyLimits($usage);
                }

                $row = $this->operationalLimitsRepository->findCompanyOperationalLimitsRow($companyId);
                if (!$row) {
                    return $this->fallbackCompanyLimits($usage);
                }

                return [
                    'max_branches_enabled' => (int) $row->max_branches_enabled,
                    'max_warehouses_enabled' => (int) $row->max_warehouses_enabled,
                    'max_cash_registers_enabled' => (int) $row->max_cash_registers_enabled,
                    'max_cash_registers_per_warehouse' => (int) ($row->max_cash_registers_per_warehouse ?? 1),
                ];
            }
        );

        $this->companyLimitsCache[$companyId] = $limits;

        return $limits;
    }

    public function updateLimits(int $companyId, array $payload, int $userId): void
    {
        $this->operationalLimitsRepository->runInTransaction(function () use ($companyId, $payload, $userId) {
            if (isset($payload['max_companies_enabled'])) {
                $this->operationalLimitsRepository->updateOrInsertPlatformLimit((int) $payload['max_companies_enabled'], $userId);
            }

            $updates = [];
            if (isset($payload['max_branches_enabled'])) {
                $updates['max_branches_enabled'] = (int) $payload['max_branches_enabled'];
            }
            if (isset($payload['max_warehouses_enabled'])) {
                $updates['max_warehouses_enabled'] = (int) $payload['max_warehouses_enabled'];
            }
            if (isset($payload['max_cash_registers_enabled'])) {
                $updates['max_cash_registers_enabled'] = (int) $payload['max_cash_registers_enabled'];
            }
            if (isset($payload['max_cash_registers_per_warehouse'])) {
                $updates['max_cash_registers_per_warehouse'] = (int) $payload['max_cash_registers_per_warehouse'];
            }

            $this->operationalLimitsRepository->updateOrInsertCompanyOperationalLimits($companyId, $updates, $userId);
        });

        $this->resetComputedCaches($companyId);
    }

    public function resolveActiveCompanyVertical(int $companyId): ?array
    {
        $resolved = Cache::remember(
            'operational_limits:active_vertical:v1:company:' . $companyId,
            self::ACTIVE_VERTICAL_CACHE_TTL_SECONDS,
            function () use ($companyId) {
                if (!$this->tableExistsCached('appcfg', 'verticals')
                    || !$this->tableExistsCached('appcfg', 'company_verticals')) {
                    return ['resolved' => false];
                }

                $row = $this->operationalLimitsRepository->findActiveCompanyVerticalRow($companyId);
                if (!$row) {
                    return ['resolved' => false];
                }

                return [
                    'resolved' => true,
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                ];
            }
        );

        if (!is_array($resolved) || !($resolved['resolved'] ?? false)) {
            return null;
        }

        return [
            'id' => (int) ($resolved['id'] ?? 0),
            'code' => (string) ($resolved['code'] ?? ''),
            'name' => (string) ($resolved['name'] ?? ''),
        ];
    }

    public function companyExists(int $companyId): bool
    {
        return $this->operationalLimitsRepository->companyExists($companyId);
    }

    public function existingCompanyIds(array $companyIds): array
    {
        return $this->operationalLimitsRepository->existingCompanyIds($companyIds);
    }

    public function listCompanyOperationalLimitMatrix(int $systemCompanyId): array
    {
        $companies = $this->operationalLimitsRepository->listNonSystemCompanies($systemCompanyId);

        $limitsByCompany = collect();
        if ($this->tableExistsCached('appcfg', 'company_operational_limits')) {
            $limitsByCompany = $this->operationalLimitsRepository->getAllCompanyOperationalLimits()->keyBy('company_id');
        }

        return $companies->map(function ($company) use ($limitsByCompany) {
            $companyId = (int) $company->id;
            $limits = $limitsByCompany->get($companyId);

            $usageBranches = $this->operationalLimitsRepository->countEnabledBranches($companyId);
            $usageWarehouses = $this->operationalLimitsRepository->countEnabledWarehouses($companyId);
            $usageCashRegisters = $this->operationalLimitsRepository->countEnabledCashRegisters($companyId);

            return [
                'company_id' => $companyId,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'max_branches_enabled' => max(1, (int) ($limits->max_branches_enabled ?? 1)),
                'max_warehouses_enabled' => max(1, (int) ($limits->max_warehouses_enabled ?? 1)),
                'max_cash_registers_enabled' => max(1, (int) ($limits->max_cash_registers_enabled ?? 1)),
                'max_cash_registers_per_warehouse' => max(1, (int) ($limits->max_cash_registers_per_warehouse ?? 1)),
                'usage_branches' => $usageBranches,
                'usage_warehouses' => $usageWarehouses,
                'usage_cash_registers' => $usageCashRegisters,
                'updated_at' => $limits->updated_at ?? null,
            ];
        })->values()->all();
    }

    public function updateCompanyOperationalLimit(int $companyId, array $payload, ?int $updatedBy): void
    {
        $updates = [
            'max_branches_enabled' => (int) $payload['max_branches_enabled'],
            'max_warehouses_enabled' => (int) $payload['max_warehouses_enabled'],
            'max_cash_registers_enabled' => (int) $payload['max_cash_registers_enabled'],
            'max_cash_registers_per_warehouse' => (int) $payload['max_cash_registers_per_warehouse'],
        ];

        $this->operationalLimitsRepository->updateOrInsertCompanyOperationalLimits($companyId, $updates, $updatedBy);
        $this->resetComputedCaches($companyId);
    }

    public function updateCompanyOperationalLimitBulk(array $companyIds, array $payload, ?int $updatedBy): void
    {
        foreach ($companyIds as $companyId) {
            $this->updateCompanyOperationalLimit((int) $companyId, $payload, $updatedBy);
        }
    }

    public function logCompanyRateLimitAudit(array $values): void
    {
        $this->operationalLimitsRepository->insertCompanyRateLimitAudit($values);
    }

    private function fallbackCompanyLimits(array $usage): array
    {
        return [
            'max_branches_enabled' => max(1, $usage['enabled_branches']),
            'max_warehouses_enabled' => max(1, $usage['enabled_warehouses']),
            'max_cash_registers_enabled' => max(1, $usage['enabled_cash_registers']),
            'max_cash_registers_per_warehouse' => 1,
        ];
    }

    private function tableExistsCached(string $schema, string $table): bool
    {
        $cacheKey = $schema . '.' . $table;
        if (!array_key_exists($cacheKey, $this->tableExistsCache)) {
            $this->tableExistsCache[$cacheKey] = Cache::remember(
                'operational_limits:table_exists:v1:' . $cacheKey,
                self::TABLE_EXISTS_CACHE_TTL_SECONDS,
                function () use ($schema, $table) {
                    return $this->operationalLimitsRepository->tableExists($schema, $table);
                }
            );
        }

        return $this->tableExistsCache[$cacheKey];
    }

    private function resetComputedCaches(?int $companyId = null): void
    {
        $this->platformLimitsCache = null;
        Cache::forget('operational_limits:platform:v1');

        if ($companyId !== null) {
            unset($this->companyLimitsCache[$companyId]);
            unset($this->usageCache[$companyId]);
            Cache::forget($this->companyLimitsSharedCacheKey($companyId));
            Cache::forget($this->usageSharedCacheKey($companyId));
            Cache::forget('operational_limits:active_vertical:v1:company:' . $companyId);
        } else {
            $this->companyLimitsCache = [];
            $this->usageCache = [];
        }
    }

    private function usageSharedCacheKey(int $companyId): string
    {
        return 'operational_limits:usage:v1:company:' . $companyId;
    }

    private function companyLimitsSharedCacheKey(int $companyId): string
    {
        return 'operational_limits:company_limits:v1:company:' . $companyId;
    }
}
