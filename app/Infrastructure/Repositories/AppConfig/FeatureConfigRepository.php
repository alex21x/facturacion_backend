<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\AppConfigVerticalReferenceDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeatureConfigRepository
{
    public function getCompanyFeatures(int $companyId): Collection
    {
        return DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->get(['feature_code', 'is_enabled', 'config'])
            ->keyBy('feature_code');
    }

    public function getBranchFeatures(int $companyId, int $branchId): Collection
    {
        return DB::table('appcfg.branch_feature_toggles')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->get(['feature_code', 'is_enabled', 'config'])
            ->keyBy('feature_code');
    }

    public function getActiveVerticalForCompany(int $companyId, bool $hasIsActiveColumn): ?AppConfigVerticalReferenceDTO
    {
        $query = DB::table('appcfg.company_verticals')
            ->join('appcfg.verticals', 'appcfg.verticals.id', '=', 'appcfg.company_verticals.vertical_id')
            ->where('appcfg.company_verticals.company_id', $companyId);

        if ($hasIsActiveColumn) {
            $query->where('appcfg.company_verticals.is_active', true);
        }

        $vertical = $query
            ->orderByDesc('appcfg.company_verticals.id')
            ->first(['appcfg.verticals.id', 'appcfg.verticals.code']);

        return $vertical ? AppConfigVerticalReferenceDTO::fromRow($vertical) : null;
    }

    public function getVerticalOverrides(int $companyId, int $verticalId): Collection
    {
        return DB::table('appcfg.company_vertical_feature_overrides')
            ->where('company_id', $companyId)
            ->where('vertical_id', $verticalId)
            ->get(['feature_code', 'is_enabled', 'config']);
    }

    public function upsertBranchFeatureToggle(array $match, array $values): void
    {
        DB::table('appcfg.branch_feature_toggles')->updateOrInsert($match, $values);
    }

    public function upsertCompanyFeatureToggle(array $match, array $values): void
    {
        DB::table('appcfg.company_feature_toggles')->updateOrInsert($match, $values);
    }

    public function getBranchIdsByCompany(int $companyId): Collection
    {
        return DB::table('core.branches')
            ->where('company_id', $companyId)
            ->pluck('id');
    }

    public function getFeatureLabels(string $labelColumn): Collection
    {
        return DB::table('appcfg.feature_labels')
            ->get(['feature_code', $labelColumn]);
    }

    public function getFeatureCategoryRows(array $codes, array $columns): Collection
    {
        return DB::table('appcfg.feature_labels')
            ->whereIn('feature_code', $codes)
            ->where('status', 1)
            ->get($columns);
    }

    public function getExistingFeatureLabels(array $codes, array $columns): Collection
    {
        return DB::table('appcfg.feature_labels')
            ->whereIn('feature_code', $codes)
            ->get($columns)
            ->keyBy('feature_code');
    }

    public function upsertFeatureLabel(string $featureCode, array $values): void
    {
        DB::table('appcfg.feature_labels')->updateOrInsert(
            ['feature_code' => $featureCode],
            $values
        );
    }

    public function tableExists(string $schema, string $table): bool
    {
        return true;
    }

    public function columnExists(string $schema, string $table, string $column): bool
    {
        return true;
    }

    public function getCommerceFeatureCodesFromLabels(): array
    {
        return DB::table('appcfg.feature_labels')
            ->where('status', 1)
            ->orderBy('feature_code')
            ->pluck('feature_code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->values()
            ->all();
    }
}
