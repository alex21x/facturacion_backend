<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanyRateLimitRepository;

class CompanyRateLimitService
{
    public function __construct(
        private CompanyRateLimitRepository $companyRateLimitRepository
    ) {
    }

    public function hasTable(): bool
    {
        return $this->companyRateLimitRepository->tableExists('appcfg', 'company_rate_limits');
    }

    public function companyExists(int $companyId): bool
    {
        return $this->companyRateLimitRepository->companyExists($companyId);
    }

    public function existingCompanyIds(array $companyIds): array
    {
        return $this->companyRateLimitRepository->existingCompanyIds($companyIds);
    }

    public function listMatrixRows(
        int $systemCompanyId,
        int $defaultRead,
        int $defaultWrite,
        int $defaultReports
    ): array {
        $companies = $this->companyRateLimitRepository->listNonSystemCompanies($systemCompanyId);

        $limitsByCompany = collect();
        if ($this->hasTable()) {
            $limitsByCompany = $this->companyRateLimitRepository->getAllCompanyRateLimits()->keyBy('company_id');
        }

        return $companies->map(function ($company) use ($limitsByCompany, $defaultRead, $defaultWrite, $defaultReports) {
            $limit = $limitsByCompany->get((int) $company->id);

            return [
                'company_id' => (int) $company->id,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'is_enabled' => $limit ? ((int) ($limit->is_enabled ?? 1) === 1) : true,
                'requests_per_minute' => $limit ? (int) ($limit->requests_per_minute ?? $defaultRead) : $defaultRead,
                'requests_per_minute_read' => $limit ? (int) ($limit->requests_per_minute_read ?? $limit->requests_per_minute ?? $defaultRead) : $defaultRead,
                'requests_per_minute_write' => $limit ? (int) ($limit->requests_per_minute_write ?? $limit->requests_per_minute ?? $defaultWrite) : $defaultWrite,
                'requests_per_minute_reports' => $limit ? (int) ($limit->requests_per_minute_reports ?? $limit->requests_per_minute ?? $defaultReports) : $defaultReports,
                'plan_code' => $limit ? (string) ($limit->plan_code ?? 'PRO') : 'PRO',
                'last_preset_code' => $limit ? ($limit->last_preset_code ? (string) $limit->last_preset_code : null) : null,
                'updated_at' => $limit->updated_at ?? null,
            ];
        })->values()->all();
    }

    public function updateCompanyRateLimit(int $companyId, array $payload, ?int $updatedBy): void
    {
        $this->companyRateLimitRepository->upsertCompanyRateLimit($companyId, $payload, $updatedBy);
    }

    public function updateCompanyRateLimitBulk(array $companyIds, array $payload, ?int $updatedBy): void
    {
        foreach ($companyIds as $companyId) {
            $this->companyRateLimitRepository->upsertCompanyRateLimit((int) $companyId, $payload, $updatedBy);
        }
    }
}
