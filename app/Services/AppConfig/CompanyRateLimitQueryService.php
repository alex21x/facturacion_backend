<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanyRateLimitRepository;
use Carbon\Carbon;

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
                'response_delay_enabled' => $limit ? (bool) ($limit->response_delay_enabled ?? false) : false,
                'response_delay_ms' => $limit ? (int) ($limit->response_delay_ms ?? 0) : 0,
                'plan_code' => $limit ? (string) ($limit->plan_code ?? 'PRO') : 'PRO',
                'last_preset_code' => $limit ? ($limit->last_preset_code ? (string) $limit->last_preset_code : null) : null,
                'updated_at' => $limit->updated_at ?? null,
            ];
        })->values()->all();
    }

    public function resolveResponseDelay(int $companyId): array
    {
        $settings = $this->companyRateLimitRepository->findCompanyResponseDelaySettings($companyId);
        if (!$settings) {
            return [
                'is_enabled' => false,
                'delay_ms' => 0,
                'has_active_subscription' => false,
            ];
        }

        $today = Carbon::today();
        $hasActiveSubscription = strtoupper((string) ($settings->subscription_status ?? '')) === 'ACTIVE'
            && in_array(strtoupper((string) ($settings->billing_cycle ?? '')), ['ANNUAL', 'MONTHLY'], true)
            && ($settings->starts_at === null || Carbon::parse($settings->starts_at)->startOfDay()->lessThanOrEqualTo($today))
            && ($settings->current_period_starts_at === null || Carbon::parse($settings->current_period_starts_at)->startOfDay()->lessThanOrEqualTo($today))
            && ($settings->current_period_ends_at === null || Carbon::parse($settings->current_period_ends_at)->startOfDay()->greaterThanOrEqualTo($today));

        return [
            'is_enabled' => (bool) $settings->response_delay_enabled,
            'delay_ms' => min(
                (int) config('company_response_delay.max_delay_ms'),
                max(0, (int) $settings->response_delay_ms)
            ),
            'has_active_subscription' => $hasActiveSubscription,
        ];
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
