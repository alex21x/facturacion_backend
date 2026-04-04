<?php

namespace App\Services\AppConfig;

use Illuminate\Support\Facades\DB;

class CommerceFeatureToggleService
{
    public function isFeatureEnabledForContext(int $companyId, $branchId, string $featureCode): bool
    {
        $normalizedBranchId = $this->normalizeBranchId($branchId);

        $branchEnabled = null;
        if ($normalizedBranchId !== null) {
            $branchToggle = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $normalizedBranchId)
                ->where('feature_code', $featureCode)
                ->first();

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
        $row = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

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
