<?php

namespace App\Services\AppConfig;

use App\Application\DTOs\AppConfig\CompanyIgvRateDTO;
use App\Infrastructure\Repositories\AppConfig\CompanyIgvRateRepository;

class CompanyIgvRateService
{
    private const DEFAULT_RATE_PERCENT = 18.0;

    public function __construct(private CompanyIgvRateRepository $companyIgvRateRepository)
    {
    }

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

        return $this->companyIgvRateRepository->runInTransaction(function () use ($companyId, $normalizedRate, $name) {
            $this->companyIgvRateRepository->deactivateActiveRates($companyId);

            $existing = $this->companyIgvRateRepository->findLatestByRate($companyId, $normalizedRate);

            if ($existing) {
                $this->companyIgvRateRepository->reactivateRate((int) $existing->id, $name);

                return [
                    'id' => (int) $existing->id,
                    'name' => $name,
                    'rate_percent' => $normalizedRate,
                    'is_active' => true,
                ];
            }

            $id = $this->companyIgvRateRepository->createRate($companyId, $name, $normalizedRate);

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

    private function resolveActiveRateRow(int $companyId): ?CompanyIgvRateDTO
    {
        if (!$this->companyIgvRateRepository->tableExists()) {
            return null;
        }

        return $this->companyIgvRateRepository->findActiveRateRow($companyId);
    }

    private function tableExists(): bool
    {
        return $this->companyIgvRateRepository->tableExists();
    }
}