<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\FeatureLabelRepository;

class FeatureLabelQueryService
{
    public function __construct(
        private FeatureLabelRepository $featureLabelRepository
    ) {
    }

    public function tableExists(string $schema, string $table): bool
    {
        return $this->featureLabelRepository->tableExists($schema, $table);
    }

    public function columnExists(string $schema, string $table, string $column): bool
    {
        return $this->featureLabelRepository->columnExists($schema, $table, $column);
    }

    public function listActiveFeatureCodes(): array
    {
        return $this->featureLabelRepository->pluckActiveFeatureCodes();
    }

    public function getActiveFeatureLabelRows(array $codes, array $columns)
    {
        return $this->featureLabelRepository->getActiveRowsByCodes($codes, $columns);
    }

    public function getFeatureLabelRows(array $codes, array $columns)
    {
        return $this->featureLabelRepository->getRowsByCodes($codes, $columns);
    }
}
