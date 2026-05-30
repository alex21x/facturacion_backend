<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\CompanyIgvRateDTO;
use Illuminate\Support\Facades\DB;

class CompanyIgvRateRepository
{
    public function tableExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'core')
            ->where('table_name', 'company_igv_rates')
            ->exists();
    }

    public function findActiveRateRow(int $companyId): ?CompanyIgvRateDTO
    {
        $rate = DB::table('core.company_igv_rates')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $rate ? CompanyIgvRateDTO::fromRow($rate) : null;
    }

    public function deactivateActiveRates(int $companyId): int
    {
        return DB::table('core.company_igv_rates')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    public function findLatestByRate(int $companyId, float $ratePercent): ?CompanyIgvRateDTO
    {
        $rate = DB::table('core.company_igv_rates')
            ->where('company_id', $companyId)
            ->where('rate_percent', $ratePercent)
            ->orderByDesc('id')
            ->first();

        return $rate ? CompanyIgvRateDTO::fromRow($rate) : null;
    }

    public function reactivateRate(int $id, string $name): int
    {
        return DB::table('core.company_igv_rates')
            ->where('id', $id)
            ->update([
                'name' => $name,
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }

    public function createRate(int $companyId, string $name, float $ratePercent): int
    {
        return (int) DB::table('core.company_igv_rates')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'rate_percent' => $ratePercent,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function runInTransaction(callable $callback)
    {
        return DB::transaction($callback);
    }
}
