<?php

namespace App\Services\AppConfig;

class FeatureLabelService
{
    public function __construct(
        private FeatureLabelQueryService $queryService,
        private FeatureLabelCommandService $commandService
    ) {
    }

    public function tableExists(string $schema, string $table): bool
    {
        return $this->queryService->tableExists($schema, $table);
    }

    public function columnExists(string $schema, string $table, string $column): bool
    {
        return $this->queryService->columnExists($schema, $table, $column);
    }

    public function listActiveFeatureCodes(): array
    {
        return $this->queryService->listActiveFeatureCodes();
    }

    public function getActiveFeatureLabelRows(array $codes, array $columns)
    {
        return $this->queryService->getActiveFeatureLabelRows($codes, $columns);
    }

    public function getFeatureLabelRows(array $codes, array $columns)
    {
        return $this->queryService->getFeatureLabelRows($codes, $columns);
    }

    public function upsertFeatureLabel(string $featureCode, array $values): void
    {
        $this->commandService->upsertFeatureLabel($featureCode, $values);
    }
}
