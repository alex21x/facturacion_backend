<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO;
use Illuminate\Support\Facades\DB;

class VerticalFeaturePreferenceRepository
{
    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function findCompanyVerticalOverride(int $companyId, int $verticalId, string $normalizedFeatureCode): ?CompanyFeatureToggleDTO
    {
        $row = DB::table('appcfg.company_vertical_feature_overrides')
            ->where('company_id', $companyId)
            ->where('vertical_id', $verticalId)
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled', 'config']);

        return $row ? CompanyFeatureToggleDTO::fromRow($row) : null;
    }

    public function findVerticalTemplate(int $verticalId, string $normalizedFeatureCode): ?CompanyFeatureToggleDTO
    {
        $row = DB::table('appcfg.vertical_feature_templates')
            ->where('vertical_id', $verticalId)
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled', 'config']);

        return $row ? CompanyFeatureToggleDTO::fromRow($row) : null;
    }
}
