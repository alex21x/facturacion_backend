<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationalLimitsRepository
{
    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function countEnabledCompanies(): int
    {
        return (int) DB::table('core.companies')
            ->where('status', 1)
            ->count();
    }

    public function countEnabledBranches(int $companyId): int
    {
        return (int) DB::table('core.branches')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->count();
    }

    public function countEnabledWarehouses(int $companyId): int
    {
        return (int) DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->count();
    }

    public function countEnabledCashRegisters(int $companyId): int
    {
        return (int) DB::table('sales.cash_registers')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->count();
    }

    public function listNonSystemCompanies(int $systemCompanyId): Collection
    {
        return DB::table('core.companies')
            ->where('id', '!=', $systemCompanyId)
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);
    }

    public function getAllCompanyOperationalLimits(): Collection
    {
        return DB::table('appcfg.company_operational_limits')
            ->get([
                'company_id',
                'max_branches_enabled',
                'max_warehouses_enabled',
                'max_cash_registers_enabled',
                'max_cash_registers_per_warehouse',
                'updated_at',
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

    public function findPlatformLimitsRow(): ?object
    {
        $row = DB::table('appcfg.platform_limits')
            ->select('max_companies_enabled')
            ->where('id', 1)
            ->first();

        return $row ? (object) $row : null;
    }

    public function findCompanyOperationalLimitsRow(int $companyId): ?object
    {
        $row = DB::table('appcfg.company_operational_limits')
            ->select('max_branches_enabled', 'max_warehouses_enabled', 'max_cash_registers_enabled', 'max_cash_registers_per_warehouse')
            ->where('company_id', $companyId)
            ->first();

        return $row ? (object) $row : null;
    }

    public function updateOrInsertPlatformLimit(int $maxCompaniesEnabled, int $updatedBy): void
    {
        DB::table('appcfg.platform_limits')->updateOrInsert(
            ['id' => 1],
            [
                'max_companies_enabled' => $maxCompaniesEnabled,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]
        );
    }

    public function updateOrInsertCompanyOperationalLimits(int $companyId, array $updates, ?int $updatedBy): void
    {
        if (empty($updates)) {
            return;
        }

        $updates['updated_by'] = $updatedBy;
        $updates['updated_at'] = now();

        DB::table('appcfg.company_operational_limits')->updateOrInsert(
            ['company_id' => $companyId],
            $updates
        );
    }

    public function runInTransaction(callable $callback): void
    {
        DB::transaction($callback);
    }

    public function insertCompanyRateLimitAudit(array $values): void
    {
        DB::table('appcfg.company_rate_limit_audit')->insert($values);
    }

    public function findActiveCompanyVerticalRow(int $companyId): ?object
    {
        $row = DB::table('appcfg.company_verticals as cv')
            ->join('appcfg.verticals as v', 'v.id', '=', 'cv.vertical_id')
            ->where('cv.company_id', $companyId)
            ->where('cv.status', 1)
            ->where('v.status', 1)
            ->where('cv.is_primary', true)
            ->select('v.id', 'v.code', 'v.name')
            ->first();

        return $row ? (object) $row : null;
    }
}
