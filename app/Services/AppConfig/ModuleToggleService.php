<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\ModuleToggleRepository;
use Illuminate\Support\Collection;

class ModuleToggleService
{
    public function __construct(
        private ModuleToggleRepository $moduleToggleRepository
    ) {
    }

    public function listModules(int $companyId, ?int $branchId): Collection
    {
        return $this->moduleToggleRepository->listModulesWithScope($companyId, $branchId);
    }

    public function getFeatureToggles(int $companyId, ?int $branchId): array
    {
        $companyFeatures = $this->moduleToggleRepository->getCompanyFeatureToggles($companyId);

        $branchFeatures = collect();
        if ($branchId !== null) {
            $branchFeatures = $this->moduleToggleRepository->getBranchFeatureToggles($companyId, $branchId);
        }

        return [
            'company_features' => $companyFeatures,
            'branch_features' => $branchFeatures,
        ];
    }
}
