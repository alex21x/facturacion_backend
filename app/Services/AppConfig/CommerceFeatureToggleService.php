<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\ModuleToggleRepository;
use Illuminate\Support\Collection;

class CommerceFeatureToggleService
{
    private array $companyTogglesCache = [];
    private array $branchTogglesCache = [];

    public function __construct(private ModuleToggleRepository $moduleToggleRepository)
    {
    }

    public function isFeatureEnabledForContext(int $companyId, $branchId, string $featureCode): bool
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $normalizedBranchId = $this->normalizeBranchId($branchId);

        $branchEnabled = null;
        if ($normalizedBranchId !== null) {
            $branchToggle = $this->getBranchToggles($companyId, $normalizedBranchId)->get($normalizedFeatureCode);

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
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $row = $this->getCompanyToggles($companyId)->get($normalizedFeatureCode);

        return $row ? (bool) ($row->is_enabled ?? false) : false;
    }

    private function getCompanyToggles(int $companyId): Collection
    {
        if (!array_key_exists($companyId, $this->companyTogglesCache)) {
            $this->companyTogglesCache[$companyId] = $this->moduleToggleRepository->getCompanyFeatureToggles($companyId);
        }

        return $this->companyTogglesCache[$companyId];
    }

    private function getBranchToggles(int $companyId, int $branchId): Collection
    {
        $cacheKey = $companyId . ':' . $branchId;
        if (!array_key_exists($cacheKey, $this->branchTogglesCache)) {
            $this->branchTogglesCache[$cacheKey] = $this->moduleToggleRepository->getBranchFeatureToggles($companyId, $branchId);
        }

        return $this->branchTogglesCache[$cacheKey];
    }

    private function normalizeBranchId($branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        return (int) $branchId;
    }
}
