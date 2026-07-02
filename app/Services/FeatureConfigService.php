<?php

namespace App\Services;

use App\Infrastructure\Repositories\AppConfig\FeatureConfigRepository;
use Illuminate\Support\Facades\Cache;

class FeatureConfigService
{
    private const CACHE_PREFIX = 'feature_config:';
    private const CACHE_SCHEMA_VERSION = 'v3';

    public function __construct(private FeatureConfigRepository $featureConfigRepository)
    {
    }

    /**
     * Get commerce settings for a company/branch.
     * Optimized: 2 queries max (company + branch toggles), cached in Redis.
     */
    public function getCommerceSettings(int $companyId, ?int $branchId = null): array
    {
        $codes = $this->getCommerceFeatureCodes();

        // BATCH QUERY 1: Get all company features in ONE query
        $companyFeatures = $this->featureConfigRepository->getCompanyFeatures($companyId);

        // BATCH QUERY 2: Get all branch features in ONE query (if branch exists)
        $branchFeatures = collect();
        if ($branchId !== null) {
            $branchFeatures = $this->featureConfigRepository->getBranchFeatures($companyId, $branchId);
        }

        // Batch resolve vertical preferences (1 query instead of N)
        $verticalPreferences = $this->getVerticalPreferencesForCompany($companyId);

        // Merge everything in memory (no additional queries)
        $labelsByCode = $this->getFeatureLabels();
        $categoriesByCode = $this->getFeatureCategories();

        $features = [];
        foreach ($codes as $code) {
            $companyRow = $companyFeatures->get($code);
            $branchRow = $branchFeatures->get($code);
            $hasExplicitToggle = $companyRow !== null || $branchRow !== null;

            $companyConfig = $companyRow ? $this->decodeJsonConfig($companyRow->config) : null;
            $branchConfig = $branchRow ? $this->decodeJsonConfig($branchRow->config) : null;

            $isEnabled = $branchRow && $branchRow->is_enabled !== null
                ? (bool)$branchRow->is_enabled
                : ($companyRow ? (bool)$companyRow->is_enabled : false);

            // Merge configs: company + branch
            $resolvedConfig = is_array($companyConfig) || is_array($branchConfig)
                ? array_merge(is_array($companyConfig) ? $companyConfig : [], is_array($branchConfig) ? $branchConfig : [])
                : ($branchConfig ?? $companyConfig);

            // Vertical preference acts only as fallback when there is no explicit
            // company/branch toggle persisted for this feature.
            $verticalPref = $verticalPreferences[$code] ?? null;
            $verticalSource = null;
            if (!$hasExplicitToggle && $verticalPref && $verticalPref['resolved']) {
                if ($verticalPref['is_enabled'] !== null) {
                    $isEnabled = (bool)$verticalPref['is_enabled'];
                }
                if ($verticalPref['config'] !== null) {
                    $resolvedConfig = $verticalPref['config'];
                }
                $verticalSource = $verticalPref['source'] ?? null;
            }

            $features[] = [
                'feature_code' => $code,
                'feature_label' => $labelsByCode[$code] ?? $code,
                'feature_category_key' => $categoriesByCode[$code]['key'] ?? $this->deriveFeatureCategoryKey($code),
                'feature_category_label' => $categoriesByCode[$code]['label'] ?? $this->humanizeCategoryKey($this->deriveFeatureCategoryKey($code)),
                'is_enabled' => $isEnabled,
                'config' => $resolvedConfig,
                'vertical_source' => $verticalSource,
            ];
        }

        return [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'features' => $features,
        ];
    }

    /**
     * Get vertical preferences for ALL features in one batch.
     * Instead of N queries, do 1-2 queries and merge in memory.
     */
    private function getVerticalPreferencesForCompany(int $companyId): array
    {
        $result = [];

        try {
            // Prefer explicit active marker when available.
            $activeVertical = $this->featureConfigRepository->getActiveVerticalForCompany($companyId, true);
        } catch (\Throwable) {
            // Legacy schemas may not have is_active.
            try {
                $activeVertical = $this->featureConfigRepository->getActiveVerticalForCompany($companyId, false);
            } catch (\Throwable) {
                return [];
            }
        }

        if (!$activeVertical) {
            return [];
        }

        // Superadmin-only codes are never resolved from vertical templates/overrides.
        $superadminOnly = array_map('strtoupper', config('features.superadmin_only_feature_codes', []));

        // BATCH QUERY: Get ALL vertical feature overrides for this company+vertical in ONE query
        $overrides = $this->featureConfigRepository->getVerticalOverrides($companyId, (int) $activeVertical->id);

        // Build result map in memory
        foreach ($overrides as $override) {
            $code = strtoupper(trim($override->feature_code));
            // Skip codes that are exclusively managed by the Admin Portal.
            if (in_array($code, $superadminOnly, true)) {
                continue;
            }
            $result[$code] = [
                'resolved' => true,
                'is_enabled' => $override->is_enabled !== null ? (bool)$override->is_enabled : null,
                'config' => $override->config !== null ? $this->decodeJsonConfig($override->config) : null,
                'source' => $activeVertical->code,
            ];
        }

        return $result;
    }

    /**
     * Update commerce settings - cache invalidation included.
     */
    public function updateCommerceSettings(int $companyId, ?int $branchId, array $features, int $userId = 1): array
    {
        foreach ($features as $feature) {
            $match = [
                'company_id' => $companyId,
                'feature_code' => $feature['feature_code'],
            ];

            $config = $feature['config'] ?? null;

            if ($branchId !== null) {
                $match['branch_id'] = $branchId;
                $this->featureConfigRepository->upsertBranchFeatureToggle(
                    $match,
                    [
                        'is_enabled' => (bool)($feature['is_enabled'] ?? false),
                        'config' => $config ? $this->encodeJsonConfig($config) : null,
                        'updated_by' => $userId,
                        'updated_at' => now(),
                    ]
                );
            } else {
                $this->featureConfigRepository->upsertCompanyFeatureToggle(
                    $match,
                    [
                        'is_enabled' => (bool)($feature['is_enabled'] ?? false),
                        'config' => $config ? $this->encodeJsonConfig($config) : null,
                        'updated_by' => $userId,
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // INVALIDATE CACHE for this company (all branches)
        $this->invalidateCompanyCache($companyId, $branchId);

        // Reload and return updated settings
        return $this->getCommerceSettings($companyId, $branchId);
    }

    /**
     * Invalidate cache when settings change.
     * Clears company-level cache and all branch-level caches for the company.
     */
    public function invalidateCompanyCache(int $companyId, ?int $branchId = null): void
    {
        // Always clear company-level cache
        Cache::forget(self::CACHE_PREFIX . self::CACHE_SCHEMA_VERSION . ":company:{$companyId}:branch:null");

        if ($branchId !== null) {
            // Also clear the specific branch
            Cache::forget(self::CACHE_PREFIX . self::CACHE_SCHEMA_VERSION . ":company:{$companyId}:branch:{$branchId}");
        } else {
            // Clear all branches for this company
            $branchIds = $this->featureConfigRepository->getBranchIdsByCompany($companyId);

            foreach ($branchIds as $bid) {
                Cache::forget(self::CACHE_PREFIX . self::CACHE_SCHEMA_VERSION . ":company:{$companyId}:branch:{$bid}");
            }
        }
    }

    /**
     * Alias used by AppConfigController for admin-level toggle changes.
     */
    public function invalidateCache(int $companyId, ?int $branchId = null): void
    {
        $this->invalidateCompanyCache($companyId, $branchId);
    }

    /**
     * Get feature labels (cached or from db).
     */
    private function getFeatureLabels(): array
    {
        $configured = config('features.feature_labels_es', []);

        if (!is_array($configured)) {
            return [];
        }

        return collect($configured)
            ->mapWithKeys(function ($label, $code): array {
                $normalizedCode = strtoupper(trim((string) $code));
                if ($normalizedCode === '') {
                    return [];
                }

                $normalizedLabel = trim((string) $label);
                return [$normalizedCode => ($normalizedLabel !== '' ? $normalizedLabel : $normalizedCode)];
            })
            ->all();
    }

    /**
     * Get feature categories (cached or from db).
     */
    private function getFeatureCategories(): array
    {
        $codes = $this->getCommerceFeatureCodes();
        $categories = [];

        foreach ($codes as $code) {
            if (!array_key_exists($code, $categories)) {
                $key = $this->deriveFeatureCategoryKey((string) $code);
                $categories[$code] = [
                    'key' => $key,
                    'label' => $this->humanizeCategoryKey($key),
                ];
            }
        }

        return $categories;
    }

    /**
     * Persist labels for any new feature codes so API stays DB-driven.
     */
    private function ensureFeatureLabelsPersisted(array $featureCodes): void
    {
        if (!$this->tableExists('appcfg', 'feature_labels')) {
            return;
        }

        $codes = collect($featureCodes)
            ->filter(fn ($code) => is_string($code) && trim($code) !== '')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return;
        }

        $columns = ['feature_code', 'label_es'];
        $hasCategoryKey = $this->columnExists('appcfg', 'feature_labels', 'category_key');
        $hasCategoryLabel = $this->columnExists('appcfg', 'feature_labels', 'category_label');
        if ($hasCategoryKey) {
            $columns[] = 'category_key';
        }
        if ($hasCategoryLabel) {
            $columns[] = 'category_label';
        }

        $existing = $this->featureConfigRepository->getExistingFeatureLabels($codes->all(), $columns);

        foreach ($codes as $code) {
            $label = $this->humanizeFeatureCode((string) $code);
            $current = $existing->get($code);
            $currentLabel = trim((string) ($current->label_es ?? ''));
            $categoryKey = $this->deriveFeatureCategoryKey((string) $code);
            $categoryLabel = $this->humanizeCategoryKey($categoryKey);
            $shouldReplace = $current === null
                || $currentLabel === ''
                || strcasecmp($currentLabel, $code) === 0
                || $this->isAutogeneratedEnglishFeatureLabel($currentLabel, (string) $code);
            $currentCategoryKey = strtolower(trim((string) ($current->category_key ?? '')));
            $currentCategoryLabel = trim((string) ($current->category_label ?? ''));
            $shouldUpdateCategory = $current === null || $currentCategoryKey === '' || $currentCategoryLabel === '';

            if (!$shouldReplace && !$shouldUpdateCategory) {
                continue;
            }

            $values = [
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($shouldReplace) {
                $values['label_es'] = $label;
                $values['description'] = $label;
            }

            if ($hasCategoryKey) {
                $values['category_key'] = $categoryKey;
            }
            if ($hasCategoryLabel) {
                $values['category_label'] = $categoryLabel;
            }

            $this->featureConfigRepository->upsertFeatureLabel((string) $code, $values);
        }
    }

    private function humanizeFeatureCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return '';
        }

        $configured = config('features.feature_labels_es.' . $normalized);
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $humanized = str_replace('_', ' ', $normalized);
        $humanized = preg_replace('/\s+/', ' ', $humanized ?? '') ?? $normalized;

        return ucwords(strtolower(trim($humanized)));
    }

    private function isAutogeneratedEnglishFeatureLabel(string $label, string $code): bool
    {
        $normalizedLabel = trim($label);
        if ($normalizedLabel === '') {
            return false;
        }

        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            return false;
        }

        $humanized = str_replace('_', ' ', $normalizedCode);
        $humanized = preg_replace('/\s+/', ' ', $humanized ?? '') ?? $normalizedCode;
        $englishAuto = ucwords(strtolower(trim($humanized)));

        return strcasecmp($normalizedLabel, $englishAuto) === 0;
    }

    private function deriveFeatureCategoryKey(string $featureCode): string
    {
        $normalized = strtoupper(trim($featureCode));
        if ($normalized === '') {
            return 'general';
        }

        $parts = explode('_', $normalized, 2);
        $candidate = strtolower(trim((string) ($parts[0] ?? '')));

        return $candidate !== '' ? $candidate : 'general';
    }

    private function humanizeCategoryKey(string $categoryKey): string
    {
        $normalized = strtolower(trim($categoryKey));
        if ($normalized === '') {
            return 'General';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }

    /**
     * Get the canonical list of commerce feature codes.
     * Source of truth: appcfg.feature_labels (status=1), ordered by feature_code.
     * Fallback: config('features.commerce_feature_codes') for environments where
     * the migration has not run yet (empty array by default after cleanup).
     */
    private function getCommerceFeatureCodes(): array
    {
        return collect(config('features.commerce_feature_codes', []))
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter(fn ($c) => $c !== '')
            ->values()
            ->all();
    }

    /**
     * Decode JSON config safely.
     */
    private function decodeJsonConfig(?string $json): ?array
    {
        if (!$json) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Encode config to JSON.
     */
    private function encodeJsonConfig(?array $config): ?string
    {
        if (!is_array($config)) {
            return null;
        }
        try {
            return json_encode($config, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return null;
        }
    }
}
