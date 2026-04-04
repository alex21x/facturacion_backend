<?php

namespace App\Services\AppConfig;

use Illuminate\Support\Facades\DB;

class CompanyIgvRateService
{
    private const DEFAULT_RATE_PERCENT = 18.0;

    public function resolveActiveRatePercent(int $companyId): float
    {
        $row = $this->resolveActiveRateRow($companyId);

        if (!$row) {
            return self::DEFAULT_RATE_PERCENT;
        }

        return round((float) ($row->rate_percent ?? self::DEFAULT_RATE_PERCENT), 4);
    }

    public function resolveActiveRate(int $companyId): array
    {
        $row = $this->resolveActiveRateRow($companyId);

        if (!$row) {
            return [
                'id' => null,
                'name' => 'IGV ' . number_format(self::DEFAULT_RATE_PERCENT, 2, '.', '') . '%',
                'rate_percent' => self::DEFAULT_RATE_PERCENT,
                'is_active' => true,
            ];
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'rate_percent' => round((float) $row->rate_percent, 4),
            'is_active' => (bool) $row->is_active,
        ];
    }

    public function setActiveRatePercent(int $companyId, float $ratePercent): array
    {
        $normalizedRate = round(max(0, $ratePercent), 4);
        $name = 'IGV ' . number_format($normalizedRate, 2, '.', '') . '%';

        if (!$this->tableExists()) {
            return [
                'id' => null,
                'name' => $name,
                'rate_percent' => $normalizedRate,
                'is_active' => true,
            ];
        }

        return DB::transaction(function () use ($companyId, $normalizedRate, $name) {
            DB::table('core.company_igv_rates')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            $existing = DB::table('core.company_igv_rates')
                ->where('company_id', $companyId)
                ->where('rate_percent', $normalizedRate)
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                DB::table('core.company_igv_rates')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $name,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);

                return [
                    'id' => (int) $existing->id,
                    'name' => $name,
                    'rate_percent' => $normalizedRate,
                    'is_active' => true,
                ];
            }

            $id = DB::table('core.company_igv_rates')->insertGetId([
                'company_id' => $companyId,
                'name' => $name,
                'rate_percent' => $normalizedRate,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => (int) $id,
                'name' => $name,
                'rate_percent' => $normalizedRate,
                'is_active' => true,
            ];
        });
    }

    public function applyActiveRateToTaxCategories(int $companyId, iterable $categories): array
    {
        $activeRate = $this->resolveActiveRatePercent($companyId);
        $normalizedCategories = [];

        foreach ($categories as $category) {
            $row = is_array($category) ? $category : (array) $category;
            $code = strtoupper(trim((string) ($row['code'] ?? '')));

            if (preg_match('/^1\d$/', $code) === 1) {
                $row['rate_percent'] = round($activeRate, 4);
            }

            $normalizedCategories[] = $row;
        }

        return $normalizedCategories;
    }

    private function resolveActiveRateRow(int $companyId): ?object
    {
        if (!$this->tableExists()) {
            return null;
        }

        return DB::table('core.company_igv_rates')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function tableExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'core')
            ->where('table_name', 'company_igv_rates')
            ->exists();
    }
}