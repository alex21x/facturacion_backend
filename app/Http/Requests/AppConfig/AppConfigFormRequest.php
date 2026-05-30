<?php

namespace App\Http\Requests\AppConfig;

use App\Http\Requests\Api\ApiFormRequest;
use App\Services\AppConfig\FeatureLabelService;

abstract class AppConfigFormRequest extends ApiFormRequest
{
    protected function resolveAllowedFeatureCodes(): array
    {
        $featureLabelService = app(FeatureLabelService::class);

        if ($featureLabelService->tableExists('appcfg', 'feature_labels')
            && $featureLabelService->columnExists('appcfg', 'feature_labels', 'feature_code')) {
            $codes = collect($featureLabelService->listActiveFeatureCodes())
                ->map(fn ($code) => strtoupper(trim((string) $code)))
                ->filter(fn ($code) => $code !== '')
                ->values()
                ->all();

            if (!empty($codes)) {
                return $codes;
            }
        }

        return config('features.commerce_feature_codes', []);
    }

    protected function hasInventoryLowStockAlertThreshold(): bool
    {
        $featureLabelService = app(FeatureLabelService::class);

        return $featureLabelService->columnExists('inventory', 'inventory_settings', 'low_stock_alert_threshold');
    }
}