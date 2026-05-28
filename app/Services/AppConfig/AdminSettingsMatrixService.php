<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\AdminSettingsMatrixRepository;
use Illuminate\Support\Collection;

class AdminSettingsMatrixService
{
    public function __construct(private AdminSettingsMatrixRepository $repository)
    {
    }

    public function listNonSystemCompanies(int $systemCompanyId): Collection
    {
        return $this->repository->listNonSystemCompanies($systemCompanyId);
    }

    public function companyExists(int $companyId): bool
    {
        return $this->repository->companyExists($companyId);
    }

    public function getFeatureTogglesByCodes(array $featureCodes): Collection
    {
        return $this->repository->getFeatureTogglesByCodes($featureCodes);
    }

    public function getFeatureTogglesByCode(string $featureCode): Collection
    {
        return $this->repository->getFeatureTogglesByCode($featureCode);
    }

    public function getFeatureToggleRow(int $companyId, string $featureCode): ?object
    {
        return $this->repository->getFeatureToggleRow($companyId, $featureCode);
    }

    public function upsertCompanyFeatureTogglesBulk(int $companyId, array $valuesByCode): void
    {
        $this->repository->upsertCompanyFeatureTogglesBulk($companyId, $valuesByCode);
    }

    public function upsertCompanyFeatureToggle(int $companyId, string $featureCode, array $values): void
    {
        $this->repository->upsertCompanyFeatureToggle($companyId, $featureCode, $values);
    }

    public function getInventorySettingsByCompany(): Collection
    {
        return $this->repository->getInventorySettingsByCompany();
    }

    public function ensureInventorySettingsSchema(): void
    {
        $this->repository->ensureInventorySettingsSchema();
    }

    public function upsertInventorySettings(int $companyId, array $updates): void
    {
        $this->repository->upsertInventorySettings($companyId, $updates);
    }
}
