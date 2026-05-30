<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\OperationalLimitsRepository;

class OperationalLimitsService
{
    public function __construct(
        private OperationalLimitsRepository $operationalLimitsRepository
    ) {
    }

    public function hasRequiredTables(): bool
    {
        return $this->operationalLimitsRepository->tableExists('appcfg', 'platform_limits')
            && $this->operationalLimitsRepository->tableExists('appcfg', 'company_operational_limits');
    }

    public function getUsage(int $companyId): array
    {
        return [
            'enabled_companies' => $this->operationalLimitsRepository->countEnabledCompanies(),
            'enabled_branches' => $this->operationalLimitsRepository->countEnabledBranches($companyId),
            'enabled_warehouses' => $this->operationalLimitsRepository->countEnabledWarehouses($companyId),
            'enabled_cash_registers' => $this->operationalLimitsRepository->countEnabledCashRegisters($companyId),
        ];
    }

    public function getPlatformLimits(): array
    {
        $enabledCompanies = $this->operationalLimitsRepository->countEnabledCompanies();

        if (!$this->operationalLimitsRepository->tableExists('appcfg', 'platform_limits')) {
            return [
                'max_companies_enabled' => max(1, $enabledCompanies),
            ];
        }

        $row = $this->operationalLimitsRepository->findPlatformLimitsRow();

        return [
            'max_companies_enabled' => $row ? (int) $row->max_companies_enabled : max(1, $enabledCompanies),
        ];
    }

    public function getCompanyLimits(int $companyId): array
    {
        $usage = $this->getUsage($companyId);

        if (!$this->operationalLimitsRepository->tableExists('appcfg', 'company_operational_limits')) {
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
    }

    public function resolveActiveCompanyVertical(int $companyId): ?array
    {
        if (!$this->operationalLimitsRepository->tableExists('appcfg', 'verticals')
            || !$this->operationalLimitsRepository->tableExists('appcfg', 'company_verticals')) {
            return null;
        }

        $row = $this->operationalLimitsRepository->findActiveCompanyVerticalRow($companyId);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
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
        if ($this->operationalLimitsRepository->tableExists('appcfg', 'company_operational_limits')) {
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
}
