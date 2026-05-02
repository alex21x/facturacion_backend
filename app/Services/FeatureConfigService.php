<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class FeatureConfigService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'feature_config:';

    /**
     * Get commerce settings for a company/branch.
     * Optimized: 2 queries max (company + branch toggles), cached in Redis.
     */
    public function getCommerceSettings(int $companyId, ?int $branchId = null): array
    {
        // Cache key: feature_config:company:1:branch:1 or feature_config:company:1:branch:null
        $cacheKey = self::CACHE_PREFIX . "company:{$companyId}:branch:" . ($branchId ?? 'null');
        
        // Try cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // BATCH QUERY 1: Get all company features in ONE query
        $companyFeatures = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->get(['feature_code', 'is_enabled', 'config'])
            ->keyBy('feature_code');

        // BATCH QUERY 2: Get all branch features in ONE query (if branch exists)
        $branchFeatures = collect();
        if ($branchId !== null) {
            $branchFeatures = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->get(['feature_code', 'is_enabled', 'config'])
                ->keyBy('feature_code');
        }

        // Batch resolve vertical preferences (1 query instead of N)
        $verticalPreferences = $this->getVerticalPreferencesForCompany($companyId);

        // Merge everything in memory (no additional queries)
        $labelsByCode = $this->getFeatureLabels();
        $categoriesByCode = $this->getFeatureCategories();

        $features = [];
        foreach ($this->getCommerceFeatureCodes() as $code) {
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

        $result = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'features' => $features,
        ];

        // Cache for 1 hour
        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Get vertical preferences for ALL features in one batch.
     * Instead of N queries, do 1-2 queries and merge in memory.
     */
    private function getVerticalPreferencesForCompany(int $companyId): array
    {
        $result = [];

        // Check if vertical tables exist
        if (!$this->allTablesExist(['appcfg.verticals', 'appcfg.company_verticals', 'appcfg.vertical_feature_templates', 'appcfg.company_vertical_feature_overrides'])) {
            return [];
        }

        // Find active vertical for this company
        $activeVerticalQuery = DB::table('appcfg.company_verticals')
            ->join('appcfg.verticals', 'appcfg.verticals.id', '=', 'appcfg.company_verticals.vertical_id')
            ->where('appcfg.company_verticals.company_id', $companyId);

        // Backward-compatible schema guard: older databases may not have is_active.
        if ($this->columnExists('appcfg', 'company_verticals', 'is_active')) {
            $activeVerticalQuery->where('appcfg.company_verticals.is_active', true);
        }

        $activeVertical = $activeVerticalQuery
            ->orderByDesc('appcfg.company_verticals.id')
            ->first(['appcfg.verticals.id', 'appcfg.verticals.code']);

        if (!$activeVertical) {
            return [];
        }

        // BATCH QUERY: Get ALL vertical feature overrides for this company+vertical in ONE query
        $overrides = DB::table('appcfg.company_vertical_feature_overrides')
            ->where('company_id', $companyId)
            ->where('vertical_id', $activeVertical->id)
            ->get(['feature_code', 'is_enabled', 'config']);

        // Build result map in memory
        foreach ($overrides as $override) {
            $code = strtoupper(trim($override->feature_code));
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
                DB::table('appcfg.branch_feature_toggles')->updateOrInsert(
                    $match,
                    [
                        'is_enabled' => (bool)($feature['is_enabled'] ?? false),
                        'config' => $config ? $this->encodeJsonConfig($config) : null,
                        'updated_by' => $userId,
                        'updated_at' => now(),
                    ]
                );
            } else {
                DB::table('appcfg.company_feature_toggles')->updateOrInsert(
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
        Cache::forget(self::CACHE_PREFIX . "company:{$companyId}:branch:null");

        if ($branchId !== null) {
            // Also clear the specific branch
            Cache::forget(self::CACHE_PREFIX . "company:{$companyId}:branch:{$branchId}");
        } else {
            // Clear all branches for this company
            $branchIds = DB::table('core.branches')
                ->where('company_id', $companyId)
                ->pluck('id');

            foreach ($branchIds as $bid) {
                Cache::forget(self::CACHE_PREFIX . "company:{$companyId}:branch:{$bid}");
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
        $cacheKey = self::CACHE_PREFIX . 'labels';
        
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        if (!$this->tableExists('appcfg', 'feature_labels')) {
            Cache::put($cacheKey, [], self::CACHE_TTL);
            return [];
        }

        if (!$this->columnExists('appcfg', 'feature_labels', 'feature_code')) {
            Cache::put($cacheKey, [], self::CACHE_TTL);
            return [];
        }

        $labelColumn = null;
        foreach (['label_es', 'label', 'name', 'description'] as $candidate) {
            if ($this->columnExists('appcfg', 'feature_labels', $candidate)) {
                $labelColumn = $candidate;
                break;
            }
        }

        if ($labelColumn === null) {
            Cache::put($cacheKey, [], self::CACHE_TTL);
            return [];
        }

        $this->ensureFeatureLabelsPersisted($this->getCommerceFeatureCodes());

        $rows = DB::table('appcfg.feature_labels')
            ->get(['feature_code', $labelColumn]);

        $labels = $rows->mapWithKeys(function ($row) use ($labelColumn) {
            return [(string) $row->feature_code => (string) ($row->{$labelColumn} ?? $row->feature_code)];
        })->toArray();

        Cache::put($cacheKey, $labels, self::CACHE_TTL);

        return $labels;
    }

    /**
     * Get feature categories (cached or from db).
     */
    private function getFeatureCategories(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'categories';

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $codes = $this->getCommerceFeatureCodes();
        $categories = [];

        if ($this->tableExists('appcfg', 'feature_labels') && $this->columnExists('appcfg', 'feature_labels', 'feature_code')) {
            $hasCategoryKey = $this->columnExists('appcfg', 'feature_labels', 'category_key');
            $hasCategoryLabel = $this->columnExists('appcfg', 'feature_labels', 'category_label');

            if ($hasCategoryKey || $hasCategoryLabel) {
                $columns = ['feature_code'];
                if ($hasCategoryKey) {
                    $columns[] = 'category_key';
                }
                if ($hasCategoryLabel) {
                    $columns[] = 'category_label';
                }

                $rows = DB::table('appcfg.feature_labels')
                    ->whereIn('feature_code', $codes)
                    ->where('status', 1)
                    ->get($columns);

                foreach ($rows as $row) {
                    $code = (string) ($row->feature_code ?? '');
                    if ($code === '') {
                        continue;
                    }

                    $key = strtolower(trim((string) (($row->category_key ?? '') ?: $this->deriveFeatureCategoryKey($code))));
                    if ($key === '') {
                        $key = $this->deriveFeatureCategoryKey($code);
                    }

                    $label = trim((string) ($row->category_label ?? ''));
                    if ($label === '') {
                        $label = $this->humanizeCategoryKey($key);
                    }

                    $categories[$code] = [
                        'key' => $key,
                        'label' => $label,
                    ];
                }
            }
        }

        foreach ($codes as $code) {
            if (!array_key_exists($code, $categories)) {
                $key = $this->deriveFeatureCategoryKey((string) $code);
                $categories[$code] = [
                    'key' => $key,
                    'label' => $this->humanizeCategoryKey($key),
                ];
            }
        }

        Cache::put($cacheKey, $categories, self::CACHE_TTL);

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

        $existing = DB::table('appcfg.feature_labels')
            ->whereIn('feature_code', $codes->all())
            ->get($columns)
            ->keyBy('feature_code');

        foreach ($codes as $code) {
            $label = $this->humanizeFeatureCode((string) $code);
            $current = $existing->get($code);
            $currentLabel = trim((string) ($current->label_es ?? ''));
            $categoryKey = $this->deriveFeatureCategoryKey((string) $code);
            $categoryLabel = $this->humanizeCategoryKey($categoryKey);
            $shouldReplace = $current === null || $currentLabel === '' || strcasecmp($currentLabel, $code) === 0;
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

            DB::table('appcfg.feature_labels')->updateOrInsert(
                ['feature_code' => $code],
                $values
            );
        }
    }

    private function humanizeFeatureCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return '';
        }

        $humanized = str_replace('_', ' ', $normalized);
        $humanized = preg_replace('/\s+/', ' ', $humanized ?? '') ?? $normalized;

        return ucwords(strtolower(trim($humanized)));
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
     * Check if all required tables exist.
     */
    private function allTablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            [$schema, $tableName] = explode('.', $table, 2);
            if (!$this->tableExists($schema, $tableName)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if a table exists (cached in request).
     */
    private function tableExists(string $schema, string $table): bool
    {
        static $tableCache = [];
        $key = "{$schema}.{$table}";
        
        if (!isset($tableCache[$key])) {
            $tableCache[$key] = DB::select(
                "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?)",
                [$schema, $table]
            )[0]->exists ?? false;
        }
        
        return $tableCache[$key];
    }

    /**
     * Check if a column exists in a table (cached in request).
     */
    private function columnExists(string $schema, string $table, string $column): bool
    {
        static $columnCache = [];
        $key = "{$schema}.{$table}.{$column}";

        if (!isset($columnCache[$key])) {
            $columnCache[$key] = DB::select(
                'SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?) as exists',
                [$schema, $table, $column]
            )[0]->exists ?? false;
        }

        return $columnCache[$key];
    }

    /**
     * Get the canonical list of commerce feature codes.
     * Source of truth: appcfg.feature_labels (status=1), ordered by feature_code.
     * Fallback: config('features.commerce_feature_codes') for environments where
     * the migration has not run yet (empty array by default after cleanup).
     */
    private function getCommerceFeatureCodes(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'commerce_codes';

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $codes = [];

        if ($this->tableExists('appcfg', 'feature_labels') && $this->columnExists('appcfg', 'feature_labels', 'feature_code')) {
            $rows = DB::table('appcfg.feature_labels')
                ->where('status', 1)
                ->orderBy('feature_code')
                ->pluck('feature_code')
                ->map(fn ($c) => strtoupper(trim((string) $c)))
                ->filter(fn ($c) => $c !== '')
                ->values()
                ->all();

            $codes = $rows;
        }

        // Fallback: use config list if DB is empty or table missing
        if (empty($codes)) {
            $codes = config('features.commerce_feature_codes', []);
        }

        Cache::put($cacheKey, $codes, self::CACHE_TTL);

        return $codes;
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
