<?php

namespace App\Services\AppConfig;

use App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO;
use Illuminate\Support\Collection;

class AdminSettingsMatrixService
{
    public function __construct(
        private AdminSettingsMatrixQueryService $queryService,
        private AdminSettingsMatrixCommandService $commandService
    )
    {
    }

    public function listNonSystemCompanies(int $systemCompanyId): Collection
    {
        return $this->queryService->listNonSystemCompanies($systemCompanyId);
    }

    public function companyExists(int $companyId): bool
    {
        return $this->queryService->companyExists($companyId);
    }

    public function getFeatureTogglesByCodes(array $featureCodes): Collection
    {
        return $this->queryService->getFeatureTogglesByCodes($featureCodes);
    }

    public function getFeatureTogglesByCode(string $featureCode): Collection
    {
        return $this->queryService->getFeatureTogglesByCode($featureCode);
    }

    public function getFeatureToggleRow(int $companyId, string $featureCode): ?CompanyFeatureToggleDTO
    {
        return $this->queryService->getFeatureToggleRow($companyId, $featureCode);
    }

    public function upsertCompanyFeatureTogglesBulk(int $companyId, array $valuesByCode): void
    {
        $this->commandService->upsertCompanyFeatureTogglesBulk($companyId, $valuesByCode);
    }

    public function upsertCompanyFeatureToggle(int $companyId, string $featureCode, array $values): void
    {
        $this->commandService->upsertCompanyFeatureToggle($companyId, $featureCode, $values);
    }

    public function getInventorySettingsByCompany(): Collection
    {
        return $this->queryService->getInventorySettingsByCompany();
    }

    public function ensureInventorySettingsSchema(): void
    {
        $this->commandService->ensureInventorySettingsSchema();
    }

    public function upsertInventorySettings(int $companyId, array $updates): void
    {
        $this->commandService->upsertInventorySettings($companyId, $updates);
    }
}
