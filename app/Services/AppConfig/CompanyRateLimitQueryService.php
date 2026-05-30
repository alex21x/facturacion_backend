<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanyRateLimitRepository;

class CompanyRateLimitQueryService
{
    private ?bool $hasRateLimitTable = null;
    private ?bool $hasProfileColumns = null;

    public function __construct(
        private CompanyRateLimitRepository $companyRateLimitRepository
    ) {
    }

    public function hasTable(): bool
    {
        if ($this->hasRateLimitTable === null) {
            $this->hasRateLimitTable = $this->companyRateLimitRepository->tableExists('appcfg', 'company_rate_limits');
        }

        return $this->hasRateLimitTable;
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

    public function resolveEffectiveLimit(int $companyId, string $profile, int $defaultLimit): int
    {
        if (!$this->hasTable()) {
            return $defaultLimit;
        }

        $hasProfileColumns = $this->hasProfileColumns();
        $row = $this->companyRateLimitRepository->findCompanyRateLimitByCompanyId($companyId, $hasProfileColumns);

        if (!$row) {
            return $defaultLimit;
        }

        if ((int) ($row->is_enabled ?? 1) !== 1) {
            return 0;
        }

        $profileColumn = 'requests_per_minute_' . $profile;
        $configured = 0;

        if ($hasProfileColumns && isset($row->{$profileColumn})) {
            $configured = (int) ($row->{$profileColumn} ?? 0);
        }

        if ($configured <= 0) {
            $configured = (int) ($row->requests_per_minute ?? 0);
        }

        return $configured > 0 ? $configured : $defaultLimit;
    }

    private function hasProfileColumns(): bool
    {
        if ($this->hasProfileColumns !== null) {
            return $this->hasProfileColumns;
        }

        return $this->hasProfileColumns = $this->companyRateLimitRepository->hasColumns('appcfg', 'company_rate_limits', [
            'requests_per_minute_read',
            'requests_per_minute_write',
            'requests_per_minute_reports',
        ]);
    }
}
