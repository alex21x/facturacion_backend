<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\ModuleToggleRepository;

class CommerceFeatureToggleService
{
    public function __construct(private ModuleToggleRepository $moduleToggleRepository)
    {
    }

    public function isFeatureEnabledForContext(int $companyId, $branchId, string $featureCode): bool
    {
        $normalizedBranchId = $this->normalizeBranchId($branchId);

        $branchEnabled = null;
        if ($normalizedBranchId !== null) {
            $branchToggle = $this->moduleToggleRepository
                ->findBranchFeatureToggle($companyId, $normalizedBranchId, $featureCode);

            if ($branchToggle) {
                $branchEnabled = (bool) ($branchToggle->is_enabled ?? false);
            }
        }

        if ($branchEnabled !== null) {
            return $branchEnabled;
        }

        return $this->isCompanyFeatureEnabled($companyId, $featureCode);
    }

    public function isCompanyFeatureEnabled(int $companyId, string $featureCode): bool
    {
        $row = $this->moduleToggleRepository->findCompanyFeatureToggle($companyId, $featureCode);

        return $row ? (bool) ($row->is_enabled ?? false) : false;
    }

    private function normalizeBranchId($branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        return (int) $branchId;
    }
}
