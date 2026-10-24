<?php

namespace App\Http\Requests\AppConfig;

use App\Http\Requests\Api\ApiFormRequest;
use App\Services\AppConfig\FeatureLabelService;

abstract class AppConfigFormRequest extends ApiFormRequest
{
    protected function resolveAllowedFeatureCodes(): array
    {
        $featureLabelService = app(FeatureLabelService::class);
        $configuredCodes = collect(config('features.commerce_feature_codes', []));
        $labeledCodes = collect($featureLabelService->listActiveFeatureCodes());

        return $configuredCodes
            ->merge($labeledCodes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function hasInventoryLowStockAlertThreshold(): bool
    {
        $featureLabelService = app(FeatureLabelService::class);

        return $featureLabelService->columnExists('inventory', 'inventory_settings', 'low_stock_alert_threshold');
    }
}