<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ModuleToggleRepository
{
    public function listModulesWithScope(int $companyId, ?int $branchId): Collection
    {
        return DB::table('appcfg.modules as m')
            ->leftJoin('appcfg.company_modules as cm', function ($join) use ($companyId) {
                $join->on('cm.module_id', '=', 'm.id')
                    ->where('cm.company_id', '=', $companyId);
            })
            ->leftJoin('appcfg.branch_modules as bm', function ($join) use ($companyId, $branchId) {
                $join->on('bm.module_id', '=', 'm.id')
                    ->where('bm.company_id', '=', $companyId);

                if ($branchId !== null) {
                    $join->where('bm.branch_id', '=', $branchId);
                } else {
                    $join->whereRaw('1 = 0');
                }
            })
            ->select([
                'm.id',
                'm.code',
                'm.name',
                'm.description',
                'm.is_core',
                'm.status',
                DB::raw('cm.is_enabled as company_enabled'),
                DB::raw('bm.is_enabled as branch_enabled'),
                DB::raw("CASE WHEN bm.is_enabled IS NOT NULL THEN bm.is_enabled WHEN cm.is_enabled IS NOT NULL THEN cm.is_enabled ELSE m.is_core END as is_enabled"),
            ])
            ->orderBy('m.name')
            ->get();
    }

    public function getCompanyFeatureToggles(int $companyId): Collection
    {
        return DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('feature_code');
    }

    public function getBranchFeatureToggles(int $companyId, int $branchId): Collection
    {
        return DB::table('appcfg.branch_feature_toggles')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('feature_code');
    }
}
