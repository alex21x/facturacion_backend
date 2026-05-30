<?php

namespace App\Services\AppConfig;

use App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO;
use App\Infrastructure\Repositories\AppConfig\AdminSettingsMatrixRepository;
use Illuminate\Support\Collection;

class AdminSettingsMatrixQueryService
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

    public function getFeatureToggleRow(int $companyId, string $featureCode): ?CompanyFeatureToggleDTO
    {
        return $this->repository->getFeatureToggleRow($companyId, $featureCode);
    }

    public function getInventorySettingsByCompany(): Collection
    {
        return $this->repository->getInventorySettingsByCompany();
    }
}
