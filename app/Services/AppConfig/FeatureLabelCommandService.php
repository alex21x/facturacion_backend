<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\FeatureLabelRepository;

class FeatureLabelCommandService
{
    public function __construct(
        private FeatureLabelRepository $featureLabelRepository
    ) {
    }

    public function upsertFeatureLabel(string $featureCode, array $values): void
    {
        $this->featureLabelRepository->upsertByFeatureCode($featureCode, $values);
    }
}
