<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\VerticalFeaturePreferenceRepository;

class VerticalFeaturePreferenceService
{
    public function __construct(
        private VerticalFeaturePreferenceRepository $verticalFeaturePreferenceRepository,
        private OperationalLimitsService $operationalLimitsService
    ) {
    }

    public function resolve(int $companyId, string $featureCode): array
    {
        $default = [
            'resolved' => false,
            'is_enabled' => null,
            'config' => null,
            'source' => null,
        ];

        if (!$this->verticalFeaturePreferenceRepository->tableExists('appcfg', 'verticals')
            || !$this->verticalFeaturePreferenceRepository->tableExists('appcfg', 'company_verticals')
            || !$this->verticalFeaturePreferenceRepository->tableExists('appcfg', 'vertical_feature_templates')
            || !$this->verticalFeaturePreferenceRepository->tableExists('appcfg', 'company_vertical_feature_overrides')) {
            return $default;
        }

        $activeVertical = $this->operationalLimitsService->resolveActiveCompanyVertical($companyId);
        if ($activeVertical === null) {
            return $default;
        }

        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $verticalId = (int) $activeVertical['id'];

        $override = $this->verticalFeaturePreferenceRepository->findCompanyVerticalOverride(
            $companyId,
            $verticalId,
            $normalizedFeatureCode
        );

        if ($override && ($override->is_enabled !== null || $override->config !== null)) {
            return [
                'resolved' => true,
                'is_enabled' => $override->is_enabled !== null ? (bool) $override->is_enabled : null,
                'config' => $override->config,
                'source' => 'COMPANY_VERTICAL_OVERRIDE',
            ];
        }

        $template = $this->verticalFeaturePreferenceRepository->findVerticalTemplate($verticalId, $normalizedFeatureCode);
        if ($template) {
            return [
                'resolved' => true,
                'is_enabled' => $template->is_enabled !== null ? (bool) $template->is_enabled : null,
                'config' => $template->config,
                'source' => 'VERTICAL_TEMPLATE',
            ];
        }

        return $default;
    }
}
