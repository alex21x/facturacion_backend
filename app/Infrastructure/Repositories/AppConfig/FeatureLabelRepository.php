<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeatureLabelRepository
{
    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    public function pluckActiveFeatureCodes(): array
    {
        return DB::table('appcfg.feature_labels')
            ->where('status', 1)
            ->pluck('feature_code')
            ->all();
    }

    public function getActiveRowsByCodes(array $codes, array $columns): Collection
    {
        return DB::table('appcfg.feature_labels')
            ->whereIn('feature_code', $codes)
            ->where('status', 1)
            ->get($columns);
    }

    public function getRowsByCodes(array $codes, array $columns): Collection
    {
        return DB::table('appcfg.feature_labels')
            ->whereIn('feature_code', $codes)
            ->get($columns);
    }

    public function upsertByFeatureCode(string $featureCode, array $values): void
    {
        DB::table('appcfg.feature_labels')->updateOrInsert(
            ['feature_code' => $featureCode],
            $values
        );
    }
}
