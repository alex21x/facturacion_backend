<?php

namespace App\Http\Controllers\Api;

use App\Application\DTOs\AppConfig\BackupCompanyDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppConfig\CreateAdminCompanyRequest;
use App\Http\Requests\AppConfig\RestoreSystemDatabaseBackupRequest;
use App\Http\Requests\AppConfig\UpdateCommerceSettingsRequest;
use App\Http\Requests\AppConfig\UpdateCompanyCommerceAdminMatrixRequest;
use App\Http\Requests\AppConfig\UpdateCompanyInventorySettingsAdminMatrixRequest;
use App\Http\Requests\AppConfig\UpdateCompanyOperationalLimitMatrixBulkRequest;
use App\Http\Requests\AppConfig\UpdateCompanyOperationalLimitMatrixRequest;
use App\Http\Requests\AppConfig\UpdateCompanyProfileRequest;
use App\Http\Requests\AppConfig\UpdateCompanyRateLimitMatrixBulkRequest;
use App\Http\Requests\AppConfig\UpdateCompanyRateLimitMatrixRequest;
use App\Http\Requests\AppConfig\UpdateCompanySunatReconcileAdminMatrixRequest;
use App\Http\Requests\AppConfig\UpdateCompanyVerticalAdminMatrixBulkRequest;
use App\Http\Requests\AppConfig\UpdateCompanyVerticalAdminMatrixRequest;
use App\Http\Requests\AppConfig\UpdateCompanyVerticalSettingsRequest;
use App\Http\Requests\AppConfig\UpdateIgvSettingsRequest;
use App\Http\Requests\AppConfig\UpdateOperationalLimitsRequest;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Services\AppConfig\AdminCompanyProvisioningService;
use App\Services\AppConfig\AdminSettingsMatrixService;
use App\Services\AppConfig\BackupMaintenanceService;
use App\Services\AppConfig\CompanyProfileService;
use App\Services\AppConfig\CompanyAccessLinkService;
use App\Services\AppConfig\CompanyRateLimitService;
use App\Services\AppConfig\FeatureLabelService;
use App\Services\AppConfig\HomeMetricsSummaryService;
use App\Services\AppConfig\ModuleToggleService;
use App\Services\AppConfig\OperationalLimitsService;
use App\Services\AppConfig\OperationalContextService;
use App\Services\AppConfig\StationContextService;
use App\Services\AppConfig\VerticalAdminMatrixService;
use App\Services\AppConfig\VerticalFeaturePreferenceService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use App\Services\FeatureConfigService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class AppConfigController extends Controller
{
    private const SYSTEM_COMPANY_ID = 1;
    private const SALES_TAX_BRIDGE_FEATURE_CODE = 'SALES_TAX_BRIDGE';

    private array $activeVerticalCache = [];
    private array $verticalFeaturePreferenceCache = [];

    private const COMMERCE_FEATURE_CODES = [
        'RESTAURANT_MENU_IGV_INCLUDED',
        'RESTAURANT_RECIPES_ENABLED',
        'PRODUCT_MULTI_UOM',
        'PRODUCT_UOM_CONVERSIONS',
        'PRODUCT_WHOLESALE_PRICING',
        'INVENTORY_PRODUCTS_BY_PROFILE',
        'INVENTORY_PRODUCT_MASTERS_BY_PROFILE',
        'SALES_CUSTOMER_PRICE_PROFILE',
        'SALES_WORKSHOP_MULTI_VEHICLE',
        'SALES_ORDER_MULTI_PAYMENT_ENABLED',
        'SALES_SELLER_TO_CASHIER',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
        'SALES_ALLOW_RECEIPT_WITH_RUC',
        'SALES_ANTICIPO_ENABLED',
        'SALES_TAX_BRIDGE',
        'SALES_TAX_BRIDGE_DEBUG_VIEW',
        'SALES_GLOBAL_DISCOUNT_ENABLED',
        'SALES_ITEM_DISCOUNT_ENABLED',
        'SALES_FREE_ITEMS_ENABLED',
        'SALES_DETRACCION_ENABLED',
        'SALES_RETENCION_ENABLED',
        'SALES_PERCEPCION_ENABLED',
        'PURCHASES_GLOBAL_DISCOUNT_ENABLED',
        'PURCHASES_ITEM_DISCOUNT_ENABLED',
        'PURCHASES_FREE_ITEMS_ENABLED',
        'PURCHASES_DETRACCION_ENABLED',
        'PURCHASES_RETENCION_COMPRADOR_ENABLED',
        'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
        'PURCHASES_PERCEPCION_ENABLED',
    ];

    private const ADMIN_COMMERCE_FEATURE_CODES = [
        'PRODUCT_MULTI_UOM',
        'PRODUCT_UOM_CONVERSIONS',
        'PRODUCT_WHOLESALE_PRICING',
        'SALES_CUSTOMER_PRICE_PROFILE',
        'SALES_WORKSHOP_MULTI_VEHICLE',
        'SALES_ORDER_MULTI_PAYMENT_ENABLED',
        'SALES_SELLER_TO_CASHIER',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
        'SALES_ALLOW_RECEIPT_WITH_RUC',
        'SALES_ANTICIPO_ENABLED',
        'SALES_TAX_BRIDGE',
        'SALES_TAX_BRIDGE_DEBUG_VIEW',
        'SALES_GLOBAL_DISCOUNT_ENABLED',
        'SALES_ITEM_DISCOUNT_ENABLED',
        'SALES_FREE_ITEMS_ENABLED',
        'SALES_VOID_REQUIRE_PASSWORD',
        'SALES_DETRACCION_ENABLED',
        'SALES_RETENCION_ENABLED',
        'SALES_PERCEPCION_ENABLED',
        'PURCHASES_GLOBAL_DISCOUNT_ENABLED',
        'PURCHASES_ITEM_DISCOUNT_ENABLED',
        'PURCHASES_FREE_ITEMS_ENABLED',
        'PURCHASES_DETRACCION_ENABLED',
        'PURCHASES_RETENCION_COMPRADOR_ENABLED',
        'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
        'PURCHASES_PERCEPCION_ENABLED',
    ];

    public function __construct(
        private AdminCompanyProvisioningService $adminCompanyProvisioningService,
        private AdminSettingsMatrixService $adminSettingsMatrixService,
        private BackupMaintenanceService $backupMaintenanceService,
        private CompanyProfileService $companyProfileService,
        private OperationalContextService $operationalContextService,
        private HomeMetricsSummaryService $homeMetricsSummaryService,
        private ModuleToggleService $moduleToggleService,
        private OperationalLimitsService $operationalLimitsService,
        private VerticalFeaturePreferenceService $verticalFeaturePreferenceService,
        private FeatureLabelService $featureLabelService,
        private StationContextService $stationContextService,
        private CompanyAccessLinkService $companyAccessLinkService,
        private VerticalAdminMatrixService $verticalAdminMatrixService,
        private CompanyRateLimitService $companyRateLimitService,
        private FeatureConfigService $featureConfigService
    ) {
    }

    public function operationalContext(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id', $authUser->preferred_warehouse_id ?? null);
        $cashRegisterId = $request->query('cash_register_id', $authUser->preferred_cash_register_id ?? null);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $resolvedBranchId = null;
        if ($branchId !== null && $branchId !== '') {
            $resolvedBranchId = (int) $branchId;
        }

        $resolvedWarehouseId = null;
        if ($warehouseId !== null && $warehouseId !== '') {
            $resolvedWarehouseId = (int) $warehouseId;
        }

        $resolvedCashRegisterId = null;
        if ($cashRegisterId !== null && $cashRegisterId !== '') {
            $resolvedCashRegisterId = (int) $cashRegisterId;
        }

        $stationContext = $this->resolveAuthenticatedStationContext($request, $companyId);

        if ($stationContext !== null) {
            $resolvedBranchId = $stationContext['branch_id'];
            $resolvedWarehouseId = $stationContext['warehouse_id'];
            $resolvedCashRegisterId = $stationContext['cash_register_id'];
        }

        $contextData = $this->operationalContextService->resolveContextData(
            $companyId,
            $resolvedBranchId,
            $resolvedWarehouseId,
            $resolvedCashRegisterId
        );

        $company = $contextData['company'];

        if (!$company) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $branches = $contextData['branches'];
        $warehouses = $contextData['warehouses'];
        $cashRegisters = $contextData['cash_registers'];
        $resolvedBranchId = $contextData['selected']['branch_id'];
        $resolvedWarehouseId = $contextData['selected']['warehouse_id'];
        $resolvedCashRegisterId = $contextData['selected']['cash_register_id'];

        return response()->json([
            'company' => $company,
            'active_vertical' => $this->resolveActiveCompanyVertical($companyId),
            'station' => $stationContext,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'cash_registers' => $cashRegisters,
            'selected' => [
                'company_id' => $companyId,
                'branch_id' => $resolvedBranchId,
                'warehouse_id' => $resolvedWarehouseId,
                'cash_register_id' => $resolvedCashRegisterId,
            ],
            'selection_locks' => [
                'branch' => $stationContext !== null && $stationContext['branch_id'] !== null,
                'warehouse' => $stationContext !== null && $stationContext['warehouse_id'] !== null,
                'cash_register' => $stationContext !== null && $stationContext['cash_register_id'] !== null,
            ],
            'limits' => [
                'platform' => $this->fetchPlatformLimits(),
                'company' => $this->fetchCompanyOperationalLimits($companyId),
                'usage' => $this->fetchCompanyOperationalUsage($companyId),
            ],
        ]);
    }

    public function homeMetricsSummary(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $range = (string) $request->query('range', 'DAY');
        $branchId = $request->query('branch_id');
        $warehouseId = $request->query('warehouse_id');

        $resolvedBranchId = ($branchId !== null && $branchId !== '') ? (int) $branchId : null;
        $resolvedWarehouseId = ($warehouseId !== null && $warehouseId !== '') ? (int) $warehouseId : null;

        $payload = $this->homeMetricsSummaryService->buildSummary(
            $companyId,
            $range,
            $resolvedBranchId,
            $resolvedWarehouseId
        );

        return response()->json($payload);
    }

    public function modules(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ($branchId !== null) {
            $branchId = (int) $branchId;
        }

        $rows = $this->moduleToggleService->listModules($companyId, $branchId);

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'modules' => $rows,
        ]);
    }

    public function featureToggles(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        if ($branchId !== null) {
            $branchExists = $this->operationalContextService->branchExists($companyId, $branchId);

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 403);
            }
        }

        try {
            // Use the optimized and cached resolver directly to avoid N+1 and
            // transient failures in legacy per-feature resolution paths.
            $settings = $this->featureConfigService->getCommerceSettings($companyId, $branchId);

            $features = collect($settings['features'] ?? [])->map(function ($row) use ($branchId) {
                $isEnabled = (bool) ($row['is_enabled'] ?? false);
                $config = $row['config'] ?? null;

                return [
                    'feature_code' => (string) ($row['feature_code'] ?? ''),
                    'feature_label' => (string) ($row['feature_label'] ?? ($row['feature_code'] ?? '')),
                    'feature_category_key' => (string) ($row['feature_category_key'] ?? $this->deriveFeatureCategoryKey((string) ($row['feature_code'] ?? ''))),
                    'feature_category_label' => (string) ($row['feature_category_label'] ?? $this->humanizeCategoryKey($this->deriveFeatureCategoryKey((string) ($row['feature_code'] ?? '')))),
                    'is_enabled' => $isEnabled,
                    'company_enabled' => $isEnabled,
                    'branch_enabled' => $branchId !== null ? $isEnabled : null,
                    'company_config' => $branchId === null ? $config : null,
                    'branch_config' => $branchId !== null ? $config : null,
                    'vertical_source' => $row['vertical_source'] ?? null,
                ];
            })->values();

            return response()->json([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'features' => $features,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'features' => [],
                'fallback' => true,
                'message' => 'Feature toggles temporarily unavailable',
            ], 200);
        }
    }

    public function companyVerticalSettings(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if (!$this->verticalAdminMatrixService->hasRequiredTables()) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $settings = $this->verticalAdminMatrixService->getCompanyVerticalSettings($companyId);

        return response()->json([
            'company_id' => $companyId,
            'active_vertical' => $settings['active_vertical'],
            'verticals' => $settings['verticals'],
        ]);
    }

    public function updateCompanyVerticalSettings(UpdateCompanyVerticalSettingsRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$this->verticalAdminMatrixService->hasRequiredTables()) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $payload = $request->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);
        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $verticalCode = strtoupper(trim((string) ($payload['vertical_code'] ?? '')));
        $vertical = $this->verticalAdminMatrixService->resolveVerticalByCode($verticalCode);

        if (!$vertical) {
            return response()->json([
                'message' => 'Invalid vertical code',
            ], 422);
        }

        $effectiveFrom = (string) ($payload['effective_from'] ?? now()->toDateString());

        $this->verticalAdminMatrixService->updateCompanyVerticalSettings(
            $companyId,
            (int) $vertical['id'],
            $effectiveFrom,
            (int) $authUser->id
        );

        return $this->companyVerticalSettings($request);
    }

    public function companyVerticalAdminMatrix(Request $request)
    {
        if (!$this->verticalAdminMatrixService->hasRequiredTables()) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $matrix = $this->verticalAdminMatrixService->buildAdminMatrix(self::SYSTEM_COMPANY_ID);

        $companies = collect($matrix['companies'] ?? [])->map(function (array $row) {
            $accessSlug = $row['access_slug'] ?? null;
            $row['access_url'] = $accessSlug ? $this->buildCompanyAccessUrl((string) $accessSlug) : null;

            return $row;
        })->values();

        return response()->json([
            'verticals' => $matrix['verticals'] ?? [],
            'companies' => $companies,
        ]);
    }

    public function updateCompanyVerticalAdminMatrix(UpdateCompanyVerticalAdminMatrixRequest $request)
    {
        if (!$this->verticalAdminMatrixService->hasRequiredTables()) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyId = $this->normalizeLegacyCompanyId((int) $payload['company_id']);
        $verticalCode = strtoupper(trim((string) $payload['vertical_code']));
        $isEnabled = (bool) $payload['is_enabled'];
        $makePrimary = array_key_exists('make_primary', $payload) ? (bool) $payload['make_primary'] : true;
        $effectiveFrom = (string) ($payload['effective_from'] ?? now()->toDateString());

        $existingCompanies = $this->verticalAdminMatrixService->existingCompanyIds([$companyId]);
        $companyExists = in_array($companyId, $existingCompanies, true);
        if (!$companyExists) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $vertical = $this->verticalAdminMatrixService->resolveVerticalByCode($verticalCode);

        if (!$vertical) {
            return response()->json([
                'message' => 'Invalid vertical code',
            ], 422);
        }

        $this->verticalAdminMatrixService->applyAdminMatrixUpdate(
            $companyId,
            (int) $vertical['id'],
            $isEnabled,
            $makePrimary,
            $effectiveFrom,
            (int) $authUser->id
        );

        return $this->companyVerticalAdminMatrix($request);
    }

    public function updateCompanyVerticalAdminMatrixBulk(UpdateCompanyVerticalAdminMatrixBulkRequest $request)
    {
        if (!$this->verticalAdminMatrixService->hasRequiredTables()) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyIds = collect($payload['company_ids'] ?? [])->map(fn ($id) => $this->normalizeLegacyCompanyId((int) $id))->unique()->values()->all();
        $verticalCode = strtoupper(trim((string) $payload['vertical_code']));
        $isEnabled = (bool) $payload['is_enabled'];
        $makePrimary = array_key_exists('make_primary', $payload) ? (bool) $payload['make_primary'] : true;
        $effectiveFrom = (string) ($payload['effective_from'] ?? now()->toDateString());

        if (empty($companyIds)) {
            return response()->json([
                'message' => 'company_ids is required',
            ], 422);
        }

        $existingCompanies = $this->verticalAdminMatrixService->existingCompanyIds($companyIds);

        $missing = array_values(array_diff($companyIds, $existingCompanies));
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Some companies were not found',
                'missing_company_ids' => $missing,
            ], 404);
        }

        $vertical = $this->verticalAdminMatrixService->resolveVerticalByCode($verticalCode);

        if (!$vertical) {
            return response()->json([
                'message' => 'Invalid vertical code',
            ], 422);
        }

        $this->verticalAdminMatrixService->applyAdminMatrixUpdateBulk(
            $companyIds,
            (int) $vertical['id'],
            $isEnabled,
            $makePrimary,
            $effectiveFrom,
            (int) $authUser->id
        );

        return $this->companyVerticalAdminMatrix($request);
    }

    public function companyRateLimitMatrix(Request $request)
    {
        $defaultRead = (int) env('DEFAULT_COMPANY_RATE_LIMIT_PER_MINUTE', 3600);
        $defaultWrite = (int) env('DEFAULT_COMPANY_RATE_LIMIT_WRITE_PER_MINUTE', 2400);
        $defaultReports = (int) env('DEFAULT_COMPANY_RATE_LIMIT_REPORTS_PER_MINUTE', 900);

        $rows = $this->companyRateLimitService->listMatrixRows(
            self::SYSTEM_COMPANY_ID,
            $defaultRead,
            $defaultWrite,
            $defaultReports
        );

        return response()->json([
            'defaults' => [
                'requests_per_minute_read' => $defaultRead,
                'requests_per_minute_write' => $defaultWrite,
                'requests_per_minute_reports' => $defaultReports,
            ],
            'presets' => $this->companyRateLimitPresets($defaultRead, $defaultWrite, $defaultReports),
            'companies' => $rows,
        ]);
    }

    public function updateCompanyRateLimitMatrix(UpdateCompanyRateLimitMatrixRequest $request)
    {
        if (!$this->companyRateLimitService->hasTable()) {
            return response()->json([
                'message' => 'Rate limit table not found. Execute migration 2026_04_08_000402 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyId = $this->normalizeLegacyCompanyId((int) $payload['company_id']);

        $companyExists = $this->companyRateLimitService->companyExists($companyId);
        if (!$companyExists) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $this->companyRateLimitService->updateCompanyRateLimit($companyId, $payload, $authUser ? (int) $authUser->id : null);

        $this->logCompanyRateLimitAudit(
            $companyId,
            'SINGLE',
            (string) ($payload['plan_code'] ?? 'CUSTOM'),
            isset($payload['preset_code']) ? (string) $payload['preset_code'] : null,
            (bool) $payload['is_enabled'],
            (int) $payload['requests_per_minute_read'],
            (int) $payload['requests_per_minute_write'],
            (int) $payload['requests_per_minute_reports'],
            $authUser ? (int) $authUser->id : null
        );

        return $this->companyRateLimitMatrix($request);
    }

    public function updateCompanyRateLimitMatrixBulk(UpdateCompanyRateLimitMatrixBulkRequest $request)
    {
        if (!$this->companyRateLimitService->hasTable()) {
            return response()->json([
                'message' => 'Rate limit table not found. Execute migration 2026_04_08_000402 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyIds = collect($payload['company_ids'] ?? [])->map(fn ($id) => $this->normalizeLegacyCompanyId((int) $id))->unique()->values()->all();

        $existingCompanies = $this->companyRateLimitService->existingCompanyIds($companyIds);

        $missing = array_values(array_diff($companyIds, $existingCompanies));
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Some companies were not found',
                'missing_company_ids' => $missing,
            ], 404);
        }

        $this->companyRateLimitService->updateCompanyRateLimitBulk($companyIds, $payload, $authUser ? (int) $authUser->id : null);

        foreach ($companyIds as $companyId) {
            $this->logCompanyRateLimitAudit(
                $companyId,
                'BULK',
                (string) ($payload['plan_code'] ?? 'CUSTOM'),
                isset($payload['preset_code']) ? (string) $payload['preset_code'] : null,
                (bool) $payload['is_enabled'],
                (int) $payload['requests_per_minute_read'],
                (int) $payload['requests_per_minute_write'],
                (int) $payload['requests_per_minute_reports'],
                $authUser ? (int) $authUser->id : null
            );
        }

        return $this->companyRateLimitMatrix($request);
    }

    public function companyOperationalLimitMatrix(Request $request)
    {
        $rows = $this->operationalLimitsService->listCompanyOperationalLimitMatrix(self::SYSTEM_COMPANY_ID);

        return response()->json([
            'defaults' => [
                'max_branches_enabled' => 1,
                'max_warehouses_enabled' => 1,
                'max_cash_registers_enabled' => 1,
                'max_cash_registers_per_warehouse' => 1,
            ],
            'companies' => $rows,
        ]);
    }

    public function updateCompanyOperationalLimitMatrix(UpdateCompanyOperationalLimitMatrixRequest $request)
    {
        if (!$this->tableExists('appcfg', 'company_operational_limits')) {
            return response()->json([
                'message' => 'Operational limits table not found. Execute migration 2026_04_08_000405 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = $this->normalizeLegacyCompanyId((int) $payload['company_id']);
        $companyExists = $this->operationalLimitsService->companyExists($companyId);
        if (!$companyExists) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $this->operationalLimitsService->updateCompanyOperationalLimit($companyId, $payload, $authUser ? (int) $authUser->id : null);

        return $this->companyOperationalLimitMatrix($request);
    }

    public function updateCompanyOperationalLimitMatrixBulk(UpdateCompanyOperationalLimitMatrixBulkRequest $request)
    {
        if (!$this->tableExists('appcfg', 'company_operational_limits')) {
            return response()->json([
                'message' => 'Operational limits table not found. Execute migration 2026_04_08_000405 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyIds = collect($payload['company_ids'] ?? [])->map(fn ($id) => $this->normalizeLegacyCompanyId((int) $id))->unique()->values()->all();

        $existingCompanies = $this->operationalLimitsService->existingCompanyIds($companyIds);
        $missing = array_values(array_diff($companyIds, $existingCompanies));
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Some companies were not found',
                'missing_company_ids' => $missing,
            ], 404);
        }

        $this->operationalLimitsService->updateCompanyOperationalLimitBulk($companyIds, $payload, $authUser ? (int) $authUser->id : null);

        return $this->companyOperationalLimitMatrix($request);
    }

    public function createAdminCompany(CreateAdminCompanyRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$this->tableExists('inventory', 'warehouses')) {
            return response()->json([
                'message' => 'No se puede crear empresa: falta tabla de almacenes (inventory.warehouses).',
            ], 409);
        }

        $payload = $request->validated();
        $taxId = trim((string) $payload['tax_id']);
        $adminUsername = trim((string) $payload['admin_username']);

        $taxIdExists = $this->adminCompanyProvisioningService->taxIdExists($taxId);
        if ($taxIdExists) {
            return response()->json([
                'message' => 'Ya existe una empresa con ese RUC',
            ], 422);
        }

        $usernameExists = $this->adminCompanyProvisioningService->adminUsernameExists($adminUsername);
        if ($usernameExists) {
            return response()->json([
                'message' => 'El usuario administrador ya existe',
            ], 422);
        }

        $defaultRead = (int) env('DEFAULT_COMPANY_RATE_LIMIT_PER_MINUTE', 3600);
        $defaultWrite = (int) env('DEFAULT_COMPANY_RATE_LIMIT_WRITE_PER_MINUTE', 2400);
        $defaultReports = (int) env('DEFAULT_COMPANY_RATE_LIMIT_REPORTS_PER_MINUTE', 900);

        $planCode = (string) ($payload['plan_code'] ?? 'PRO');
        $presetCode = isset($payload['preset_code']) ? (string) $payload['preset_code'] : null;

        $readRate = (int) ($payload['requests_per_minute_read'] ?? $defaultRead);
        $writeRate = (int) ($payload['requests_per_minute_write'] ?? $defaultWrite);
        $reportsRate = (int) ($payload['requests_per_minute_reports'] ?? $defaultReports);

        if ($presetCode !== null) {
            $presets = collect($this->companyRateLimitPresets($defaultRead, $defaultWrite, $defaultReports))->keyBy('code');
            if ($presets->has($presetCode)) {
                $preset = $presets->get($presetCode);
                $readRate = (int) ($preset['requests_per_minute_read'] ?? $readRate);
                $writeRate = (int) ($preset['requests_per_minute_write'] ?? $writeRate);
                $reportsRate = (int) ($preset['requests_per_minute_reports'] ?? $reportsRate);
            }
        }

        $result = $this->adminCompanyProvisioningService->createAdminCompany(
            $payload,
            $authUser,
            [
                'plan_code' => $planCode,
                'preset_code' => $presetCode,
                'read_rate' => $readRate,
                'write_rate' => $writeRate,
                'reports_rate' => $reportsRate,
            ]
        );

        return response()->json([
            'message' => 'Empresa creada correctamente desde panel admin',
            'company_id' => $result['company_id'],
            'branch_id' => $result['branch_id'],
            'admin_user_id' => $result['admin_user_id'],
            'admin_role_id' => $result['role_id'],
        ], 201);
    }

    public function resetAdminCompanyPassword(Request $request, $companyId)
    {
        $companyId = $this->normalizeLegacyCompanyId((int) $companyId);
        $hasLastTempPasswordColumn = $this->columnExists('auth', 'users', 'last_temp_password');

        if ($companyId === self::SYSTEM_COMPANY_ID) {
            return response()->json([
                'message' => 'La empresa del sistema no se administra desde este panel.',
            ], 403);
        }

        $adminUser = $this->adminCompanyProvisioningService->findActiveAdminUser($companyId);

        if (!$adminUser) {
            $this->repairCompanyAdminRoleAfterRestore($companyId);

            $adminUser = $this->adminCompanyProvisioningService->findActiveAdminUser($companyId);
        }

        if (!$adminUser) {
            return response()->json([
                'message' => 'No se encontró un usuario administrador activo para esta empresa.',
            ], 404);
        }

        $newPassword = $this->generateSecurePassword();

        $this->adminCompanyProvisioningService->updateAdminPassword(
            (int) $adminUser->id,
            $newPassword,
            $hasLastTempPasswordColumn
        );

        return response()->json([
            'username' => $adminUser->username,
            'email' => $adminUser->email,
            'new_password' => $newPassword,
            'message' => 'Contraseña reseteada correctamente.',
        ]);
    }

    public function revealAdminCompanyPassword(Request $request, $companyId)
    {
        $companyId = $this->normalizeLegacyCompanyId((int) $companyId);
        $hasLastTempPasswordColumn = $this->columnExists('auth', 'users', 'last_temp_password');

        if ($companyId === self::SYSTEM_COMPANY_ID) {
            return response()->json(['message' => 'La empresa del sistema no se administra desde este panel.'], 403);
        }

        $adminUser = $this->adminCompanyProvisioningService->findActiveAdminUser($companyId, $hasLastTempPasswordColumn);

        if (!$adminUser) {
            $this->repairCompanyAdminRoleAfterRestore($companyId);

            $adminUser = $this->adminCompanyProvisioningService->findActiveAdminUser($companyId, $hasLastTempPasswordColumn);
        }

        if (!$adminUser) {
            return response()->json(['message' => 'No se encontró un usuario administrador activo.'], 404);
        }

        if (!$hasLastTempPasswordColumn) {
            return response()->json([
                'available' => false,
                'message' => 'La visualización de contraseña temporal no está disponible en esta base local. Puedes usar "Reset pass" para generar una nueva clave.',
            ]);
        }

        if (empty($adminUser->last_temp_password)) {
            return response()->json(['available' => false, 'message' => 'No hay contraseña temporal registrada para este usuario.']);
        }

        try {
            $plainPassword = decrypt($adminUser->last_temp_password);
        } catch (\Exception $e) {
            return response()->json(['available' => false, 'message' => 'No se pudo recuperar la contraseña almacenada.']);
        }

        return response()->json([
            'available' => true,
            'username' => $adminUser->username,
            'email' => $adminUser->email,
            'password' => $plainPassword,
        ]);
    }

    public function exportSystemDatabaseBackup(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser || !$this->canExportSystemDatabaseBackup($authUser)) {
            return response()->json([
                'message' => 'Solo un superadmin del sistema puede exportar respaldos globales.',
            ], 403);
        }

        $company = $this->resolveBackupCompanyFromRequest($request);
        if ($company === null) {
            return response()->json([
                'message' => 'Empresa invalida para respaldo.',
            ], 422);
        }

        $timestamp = Carbon::now('America/Lima')->format('Ymd_His');
        $fileName = "company_{$company->id}_{$timestamp}.sql";
        $backupDir = $this->backupDirectoryForCompany((int) $company->id);

        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            return response()->json([
                'message' => 'No se pudo crear la carpeta temporal de backups.',
            ], 500);
        }

        $outputPath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

        $ok = $this->writeCompanyBackupSql((int) $company->id, $outputPath);
        if (!$ok || !is_file($outputPath) || filesize($outputPath) === 0) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }

            return response()->json([
                'message' => 'Fallo al generar respaldo por empresa.',
            ], 500);
        }

        return response()->download($outputPath, $fileName, [
            'Content-Type' => 'application/sql; charset=UTF-8',
        ]);
    }

    public function listSystemDatabaseBackups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser || !$this->canExportSystemDatabaseBackup($authUser)) {
            return response()->json([
                'message' => 'Solo un superadmin del sistema puede ver respaldos globales.',
            ], 403);
        }

        $company = $this->resolveBackupCompanyFromRequest($request);
        if ($company === null) {
            return response()->json([
                'message' => 'Empresa invalida para historial de respaldos.',
            ], 422);
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $backupDir = $this->backupDirectoryForCompany((int) $company->id);
        if (!is_dir($backupDir)) {
            $pagination = [
                'page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
            ];

            return response()->json([
                'backups' => [],
                'pagination' => $pagination,
            ]);
        }

        $files = glob($backupDir . DIRECTORY_SEPARATOR . "company_{$company->id}_*.sql") ?: [];
        $rows = [];

        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }

            $fileName = basename($path);
            if (!$this->isAllowedBackupFileName($fileName, (int) $company->id)) {
                continue;
            }

            $mtime = filemtime($path) ?: time();
            $rows[] = [
                'file_name' => $fileName,
                'size_bytes' => filesize($path) ?: 0,
                'size_label' => $this->formatBytes((int) (filesize($path) ?: 0)),
                'generated_at' => Carbon::createFromTimestamp($mtime, 'America/Lima')->toIso8601String(),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp((string) $b['generated_at'], (string) $a['generated_at']);
        });

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($rows, $offset, $perPage);

        return response()->json([
            'backups' => $pagedRows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function downloadSystemDatabaseBackupFile(Request $request, $fileName)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser || !$this->canExportSystemDatabaseBackup($authUser)) {
            return response()->json([
                'message' => 'Solo un superadmin del sistema puede descargar respaldos globales.',
            ], 403);
        }

        $company = $this->resolveBackupCompanyFromRequest($request);
        if ($company === null) {
            return response()->json([
                'message' => 'Empresa invalida para descarga de respaldos.',
            ], 422);
        }

        $resolvedName = trim((string) $fileName);
        if (!$this->isAllowedBackupFileName($resolvedName, (int) $company->id)) {
            return response()->json([
                'message' => 'Nombre de respaldo invalido.',
            ], 422);
        }

        $backupDir = $this->backupDirectoryForCompany((int) $company->id);
        $fullPath = $backupDir . DIRECTORY_SEPARATOR . $resolvedName;

        if (!is_file($fullPath)) {
            return response()->json([
                'message' => 'No se encontro el respaldo solicitado.',
            ], 404);
        }

        return response()->download($fullPath, $resolvedName, [
            'Content-Type' => 'application/sql; charset=UTF-8',
        ]);
    }

    public function restoreSystemDatabaseBackup(RestoreSystemDatabaseBackupRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser || !$this->canExportSystemDatabaseBackup($authUser)) {
            return response()->json([
                'message' => 'Solo un superadmin del sistema puede restaurar respaldos globales.',
            ], 403);
        }

        $payload = $request->validated();
        $company = $this->resolveBackupCompanyById((int) $payload['company_id']);
        if ($company === null) {
            return response()->json([
                'message' => 'Empresa invalida para restauracion.',
            ], 422);
        }

        $file = $request->file('backup_file');
        if (!$file || !$file->isValid()) {
            return response()->json([
                'message' => 'Archivo de respaldo invalido.',
            ], 422);
        }

        $sql = @file_get_contents($file->getRealPath());
        if (!is_string($sql) || trim($sql) === '') {
            return response()->json([
                'message' => 'No se pudo leer el archivo de respaldo.',
            ], 422);
        }

        $expectedMarker = '-- company_id: ' . (int) $company->id;
        if (strpos($sql, $expectedMarker) === false) {
            return response()->json([
                'message' => 'El respaldo no corresponde a la empresa seleccionada.',
            ], 422);
        }

        $sql = $this->normalizeBackupSqlForRestore($sql, (int) $company->id);

        try {
            $this->backupMaintenanceService->runRestoreTransaction(
                $sql,
                fn () => $this->repairCompanyAdminRoleAfterRestore((int) $company->id)
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Fallo al restaurar respaldo: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Respaldo restaurado correctamente para la empresa seleccionada.',
        ]);
    }

    private function generateSecurePassword(int $length = 12): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    private function canExportSystemDatabaseBackup(object $authUser): bool
    {
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        if (in_array($roleCode, ['SUPERADMIN', 'SUPER_ADMIN'], true)) {
            return true;
        }

        return (int) ($authUser->company_id ?? 0) === self::SYSTEM_COMPANY_ID;
    }

    private function isAllowedBackupFileName(string $fileName, int $companyId): bool
    {
        return (bool) preg_match('/^company_' . preg_quote((string) $companyId, '/') . '_[0-9]{8}_[0-9]{6}\.sql$/', $fileName);
    }

    private function backupDirectoryForCompany(int $companyId): string
    {
        return storage_path('app/backups/company_' . $companyId);
    }

    private function resolveBackupCompanyFromRequest(Request $request): ?BackupCompanyDTO
    {
        $companyId = $request->input('company_id');
        if ($companyId === null || $companyId === '' || filter_var($companyId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return $this->resolveBackupCompanyById((int) $companyId);
    }

    private function resolveBackupCompanyById(int $companyId): ?BackupCompanyDTO
    {
        $companyId = $this->normalizeLegacyCompanyId($companyId);
        if ($companyId <= 0 || $companyId === self::SYSTEM_COMPANY_ID) {
            return null;
        }

        return $this->backupMaintenanceService->findCompanyById($companyId);
    }

    private function writeCompanyBackupSql(int $companyId, string $outputPath): bool
    {
        $handle = @fopen($outputPath, 'wb');
        if ($handle === false) {
            return false;
        }

        try {
            fwrite($handle, "-- Company-scoped backup\n");
            fwrite($handle, '-- company_id: ' . $companyId . "\n");
            fwrite($handle, '-- generated_at: ' . Carbon::now('America/Lima')->toIso8601String() . "\n\n");
            fwrite($handle, "BEGIN;\n");
            fwrite($handle, "SET CONSTRAINTS ALL IMMEDIATE;\n");

            $tables = $this->fetchCompanyScopedTables();
            $deleteOrder = $this->resolveCompanyScopedDeleteOrder($tables);
            $insertOrder = array_reverse($deleteOrder);
            $dependentScopes = $this->buildDependentTableScopes($tables, $companyId);
            $dependentDeletes = $this->buildDeleteStatementsFromDependentScopes($dependentScopes);

            $pdo = $this->backupMaintenanceService->getPdo();

            if (!empty($dependentDeletes)) {
                fwrite($handle, "\n-- Dependent delete phase (tables without company_id)\n");
                foreach ($dependentDeletes as $statement) {
                    fwrite($handle, $statement . "\n");
                }
            }

            fwrite($handle, "\n-- Delete phase (children -> parents)\n");
            foreach ($deleteOrder as $key) {
                if (!isset($tables[$key])) {
                    continue;
                }

                $schema = $tables[$key]['schema'];
                $table = $tables[$key]['table'];

                $qualified = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
                fwrite($handle, "-- {$schema}.{$table}\n");
                fwrite($handle, "DELETE FROM {$qualified} WHERE company_id = {$companyId};\n");
            }

            fwrite($handle, "\n-- Insert phase (parents -> children)\n");
            foreach ($insertOrder as $key) {
                if (!isset($tables[$key])) {
                    continue;
                }

                $schema = $tables[$key]['schema'];
                $table = $tables[$key]['table'];

                $qualified = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
                fwrite($handle, "\n-- {$schema}.{$table}\n");

                foreach ($this->backupMaintenanceService->cursorCompanyTableRows($schema, $table, $companyId) as $row) {
                    $assoc = (array) $row;
                    if (empty($assoc)) {
                        continue;
                    }

                    $columns = [];
                    $values = [];

                    foreach ($assoc as $column => $value) {
                        $columns[] = $this->quoteIdentifier((string) $column);
                        $values[] = $this->toSqlLiteral($value, $pdo);
                    }

                    fwrite(
                        $handle,
                        sprintf(
                            "INSERT INTO %s (%s) VALUES (%s);\n",
                            $qualified,
                            implode(', ', $columns),
                            implode(', ', $values)
                        )
                    );
                }
            }

            if (!empty($dependentScopes)) {
                fwrite($handle, "\n-- Dependent insert phase (related tables without company_id)\n");
                $dependentInsertScopes = array_reverse($dependentScopes);

                foreach ($dependentInsertScopes as $scope) {
                    $schema = (string) $scope['child_schema'];
                    $table = (string) $scope['child_table'];
                    $whereSql = (string) $scope['where_sql'];
                    $qualified = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);

                    fwrite($handle, "\n-- {$schema}.{$table}\n");

                    $selectSql = sprintf('SELECT * FROM %s AS c WHERE %s', $qualified, $whereSql);
                    foreach ($this->backupMaintenanceService->cursorRawSelect($selectSql) as $row) {
                        $assoc = (array) $row;
                        if (empty($assoc)) {
                            continue;
                        }

                        $columns = [];
                        $values = [];

                        foreach ($assoc as $column => $value) {
                            $columns[] = $this->quoteIdentifier((string) $column);
                            $values[] = $this->toSqlLiteral($value, $pdo);
                        }

                        fwrite(
                            $handle,
                            sprintf(
                                "INSERT INTO %s (%s) VALUES (%s);\n",
                                $qualified,
                                implode(', ', $columns),
                                implode(', ', $values)
                            )
                        );
                    }
                }
            }

            fwrite($handle, "\nCOMMIT;\n");
        } catch (\Throwable $e) {
            fclose($handle);
            return false;
        }

        fclose($handle);
        return true;
    }

    private function fetchCompanyScopedTables(): array
    {
        $rows = $this->backupMaintenanceService->fetchCompanyScopedTableRows();

        $tables = [];
        foreach ($rows as $row) {
            $schema = (string) $row->table_schema;
            $table = (string) $row->table_name;
            $key = $schema . '.' . $table;
            $tables[$key] = [
                'schema' => $schema,
                'table' => $table,
            ];
        }

        return $tables;
    }

    private function buildDirectDependentDeleteStatements(array $tables, int $companyId): array
    {
        $scopes = $this->buildDependentTableScopes($tables, $companyId);
        return $this->buildDeleteStatementsFromDependentScopes($scopes);
    }

    private function buildDeleteStatementsFromDependentScopes(array $scopes): array
    {
        if (empty($scopes)) {
            return [];
        }

        $statements = [];
        foreach ($scopes as $scope) {
            $childKey = (string) $scope['table_key'];
            $childQualified = $this->quoteIdentifier((string) $scope['child_schema']) . '.' . $this->quoteIdentifier((string) $scope['child_table']);
            $whereSql = (string) $scope['where_sql'];

            $statements[] = sprintf('-- %s', $childKey);
            $statements[] = sprintf('DELETE FROM %s AS c WHERE %s;', $childQualified, $whereSql);
        }

        return $statements;
    }

    private function buildDependentTableScopes(array $tables, int $companyId): array
    {
        $companyScopedSet = array_fill_keys(array_keys($tables), true);
        if (empty($companyScopedSet)) {
            return [];
        }

                $fkRows = $this->backupMaintenanceService->fetchForeignKeyColumnRows();

        $conditionsByChild = [];

        foreach ($fkRows as $fkRow) {
            $childSchema = (string) $fkRow->child_schema;
            $childTable = (string) $fkRow->child_table;
            $childColumn = (string) $fkRow->child_column;
            $parentSchema = (string) $fkRow->parent_schema;
            $parentTable = (string) $fkRow->parent_table;
            $parentColumn = (string) $fkRow->parent_column;

            $childKey = $childSchema . '.' . $childTable;
            $parentKey = $parentSchema . '.' . $parentTable;

            if (isset($companyScopedSet[$childKey])) {
                continue;
            }

            if (!isset($companyScopedSet[$parentKey])) {
                continue;
            }

            if (!isset($conditionsByChild[$childKey])) {
                $conditionsByChild[$childKey] = [
                    'child_schema' => $childSchema,
                    'child_table' => $childTable,
                    'conditions' => [],
                ];
            }

            $conditionsByChild[$childKey]['conditions'][] = [
                'child_column' => $childColumn,
                'parent_schema' => $parentSchema,
                'parent_table' => $parentTable,
                'parent_column' => $parentColumn,
            ];
        }

        if (empty($conditionsByChild)) {
            return [];
        }

        $childNodes = array_keys($conditionsByChild);
        $childSet = array_fill_keys($childNodes, true);
        $edges = [];

        foreach ($conditionsByChild as $childKey => $definition) {
            foreach ($definition['conditions'] as $condition) {
                $parentKey = (string) $condition['parent_schema'] . '.' . (string) $condition['parent_table'];
                if (isset($childSet[$parentKey])) {
                    $edges[] = [$childKey, $parentKey];
                }
            }
        }

        $orderedChildren = $this->topologicalOrder($childNodes, $edges);

        $scopes = [];
        foreach ($orderedChildren as $childKey) {
            if (!isset($conditionsByChild[$childKey])) {
                continue;
            }

            $definition = $conditionsByChild[$childKey];

            $predicates = [];
            foreach ($definition['conditions'] as $condition) {
                $parentQualified = $this->quoteIdentifier((string) $condition['parent_schema']) . '.' . $this->quoteIdentifier((string) $condition['parent_table']);
                $parentColumn = $this->quoteIdentifier((string) $condition['parent_column']);
                $childColumn = $this->quoteIdentifier((string) $condition['child_column']);

                $predicates[] = sprintf(
                    'EXISTS (SELECT 1 FROM %s AS p WHERE p.%s = c.%s AND p.company_id = %d)',
                    $parentQualified,
                    $parentColumn,
                    $childColumn,
                    $companyId
                );
            }

            $scopes[] = [
                'table_key' => $childKey,
                'child_schema' => (string) $definition['child_schema'],
                'child_table' => (string) $definition['child_table'],
                'where_sql' => implode(' OR ', $predicates),
            ];
        }

        return $scopes;
    }

    private function injectDependentDeletePhaseToBackupSql(string $sql, int $companyId): string
    {
        $tables = $this->fetchCompanyScopedTables();
        $dependentDeletes = $this->buildDirectDependentDeleteStatements($tables, $companyId);
        if (empty($dependentDeletes)) {
            return $sql;
        }

        $eol = strpos($sql, "\r\n") !== false ? "\r\n" : "\n";

        $block = $eol . '-- Dependent delete phase (tables without company_id)' . $eol
            . implode($eol, $dependentDeletes)
            . $eol;

        $withDeletePhase = preg_replace(
            '/(\r?\n)-- Delete phase \(children -> parents\)(\r?\n)/',
            $block . '$1-- Delete phase (children -> parents)$2',
            $sql,
            1
        );
        if (is_string($withDeletePhase) && $withDeletePhase !== $sql) {
            return $withDeletePhase;
        }

        $withSetConstraints = preg_replace(
            '/SET CONSTRAINTS ALL IMMEDIATE;(\r?\n)/',
            'SET CONSTRAINTS ALL IMMEDIATE;$1' . $block,
            $sql,
            1
        );
        if (is_string($withSetConstraints) && $withSetConstraints !== $sql) {
            return $withSetConstraints;
        }

        $withBegin = preg_replace(
            '/BEGIN;(\r?\n)/',
            'BEGIN;$1' . $block,
            $sql,
            1
        );
        if (is_string($withBegin) && $withBegin !== $sql) {
            return $withBegin;
        }

        return $sql;
    }

    private function normalizeBackupSqlForRestore(string $sql, int $companyId): string
    {
        $insertMarker = '-- Insert phase (parents -> children)';
        $insertPos = strpos($sql, $insertMarker);

        if ($insertPos === false) {
            return $sql;
        }

        $insertSql = substr($sql, $insertPos);
        if (!is_string($insertSql) || trim($insertSql) === '') {
            return $sql;
        }

        $tables = $this->fetchCompanyScopedTables();
        $insertSql = $this->reorderInsertPhaseSql($insertSql);

        // Remove transaction control statements from uploaded file; restore controller manages transaction.
        $insertSql = preg_replace('/^\s*(BEGIN;|COMMIT;|SET CONSTRAINTS ALL IMMEDIATE;)\s*$/mi', '', $insertSql) ?: $insertSql;

        $cleanupSql = $this->buildCompanyCleanupSql($companyId, $tables);
        if ($cleanupSql === '') {
            return $sql;
        }

        return "SET CONSTRAINTS ALL IMMEDIATE;\n"
            . $cleanupSql
            . "\n"
            . trim($insertSql)
            . "\n";
    }

    private function reorderInsertPhaseSql(string $insertSql): string
    {
        $matches = [];
        preg_match_all('/INSERT\s+INTO\s+"([^"]+)"\."([^"]+)"\s*\(.*?\);/is', $insertSql, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $insertSql;
        }

        $statementsByTable = [];
        foreach ($matches as $match) {
            $schema = (string) ($match[1] ?? '');
            $table = (string) ($match[2] ?? '');
            $statement = trim((string) ($match[0] ?? ''));

            if ($schema === '' || $table === '' || $statement === '') {
                continue;
            }

            $key = $schema . '.' . $table;
            if (!isset($statementsByTable[$key])) {
                $statementsByTable[$key] = [];
            }
            $statementsByTable[$key][] = $statement;
        }

        if (empty($statementsByTable)) {
            return $insertSql;
        }

        $orderedKeys = $this->resolveInsertOrderForTables(array_keys($statementsByTable));
        $lines = ['-- Insert phase (parents -> children)'];

        foreach ($orderedKeys as $key) {
            if (!isset($statementsByTable[$key])) {
                continue;
            }

            $lines[] = '-- ' . $key;
            foreach ($statementsByTable[$key] as $statement) {
                $lines[] = $statement;
            }
            unset($statementsByTable[$key]);
        }

        if (!empty($statementsByTable)) {
            ksort($statementsByTable);
            foreach ($statementsByTable as $key => $statements) {
                $lines[] = '-- ' . $key;
                foreach ($statements as $statement) {
                    $lines[] = $statement;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function buildCompanyCleanupSql(int $companyId, array $tables): string
    {
        $deleteOrder = $this->resolveCompanyScopedDeleteOrder($tables);
        $dependentDeletes = $this->buildDirectDependentDeleteStatements($tables, $companyId);

        $lines = [];

        if (!empty($dependentDeletes)) {
            $lines[] = '-- Dependent delete phase (tables without company_id)';
            foreach ($dependentDeletes as $statement) {
                $lines[] = $statement;
            }
        }

        $lines[] = '-- Delete phase (children -> parents)';
        foreach ($deleteOrder as $key) {
            if (!isset($tables[$key])) {
                continue;
            }

            $schema = $tables[$key]['schema'];
            $table = $tables[$key]['table'];
            $qualified = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);

            $lines[] = '-- ' . $schema . '.' . $table;
            $lines[] = 'DELETE FROM ' . $qualified . ' WHERE company_id = ' . $companyId . ';';
        }

        return implode("\n", $lines) . "\n";
    }

    private function resolveInsertOrderForTables(array $tableKeys): array
    {
        if (empty($tableKeys)) {
            return [];
        }

        $nodeSet = array_fill_keys($tableKeys, true);

        $fkRows = $this->backupMaintenanceService->fetchForeignKeyTableRows();

        $edges = [];
        foreach ($fkRows as $fkRow) {
            $childKey = (string) $fkRow->child_schema . '.' . (string) $fkRow->child_table;
            $parentKey = (string) $fkRow->parent_schema . '.' . (string) $fkRow->parent_table;

            if (!isset($nodeSet[$childKey]) || !isset($nodeSet[$parentKey])) {
                continue;
            }

            $edges[] = [$childKey, $parentKey];
        }

        return array_reverse($this->topologicalOrder($tableKeys, $edges));
    }

    private function repairCompanyAdminRoleAfterRestore(int $companyId): void
    {
        $this->adminCompanyProvisioningService->repairCompanyAdminRoleAfterRestore($companyId);
    }

    private function resolveCompanyScopedDeleteOrder(array $tables): array
    {
        $nodes = array_keys($tables);
        if (empty($nodes)) {
            return [];
        }

        $nodeSet = array_fill_keys($nodes, true);
        $fkRows = $this->backupMaintenanceService->fetchForeignKeyTableRows();

        $edges = [];
        foreach ($fkRows as $fkRow) {
            $childKey = (string) $fkRow->child_schema . '.' . (string) $fkRow->child_table;
            $parentKey = (string) $fkRow->parent_schema . '.' . (string) $fkRow->parent_table;

            if (!isset($nodeSet[$childKey]) || !isset($nodeSet[$parentKey])) {
                continue;
            }

            $edges[] = [$childKey, $parentKey];
        }

        return $this->topologicalOrder($nodes, $edges);
    }

    private function topologicalOrder(array $nodes, array $edges): array
    {
        $adjacency = [];
        $inDegree = [];

        foreach ($nodes as $node) {
            $adjacency[$node] = [];
            $inDegree[$node] = 0;
        }

        foreach ($edges as $edge) {
            $from = $edge[0];
            $to = $edge[1];

            if ($from === $to) {
                // Ignore self-referential FKs; they create artificial cycles that break global ordering.
                continue;
            }

            if (!isset($adjacency[$from]) || !isset($inDegree[$to])) {
                continue;
            }

            if (in_array($to, $adjacency[$from], true)) {
                continue;
            }

            $adjacency[$from][] = $to;
            $inDegree[$to]++;
        }

        $queue = [];
        foreach ($inDegree as $node => $degree) {
            if ($degree === 0) {
                $queue[] = $node;
            }
        }
        sort($queue);

        $ordered = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $ordered[] = $current;

            foreach ($adjacency[$current] as $next) {
                $inDegree[$next]--;
                if ($inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }

            sort($queue);
        }

        if (count($ordered) < count($nodes)) {
            $remaining = array_values(array_diff($nodes, $ordered));
            sort($remaining);
            $ordered = array_merge($ordered, $remaining);
        }

        return $ordered;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function toSqlLiteral($value, \PDO $pdo): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, 2) . ' ' . $units[$unitIndex];
    }

    public function operationalLimits(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $usage = $this->operationalLimitsService->getUsage($companyId);
        $companyLimits = $this->operationalLimitsService->getCompanyLimits($companyId);
        $platformLimits = $this->operationalLimitsService->getPlatformLimits();

        return response()->json([
            'company_id' => $companyId,
            'platform_limits' => $platformLimits,
            'company_limits' => $companyLimits,
            'usage' => $usage,
            'is_over_limit' => [
                'branches' => $usage['enabled_branches'] > $companyLimits['max_branches_enabled'],
                'warehouses' => $usage['enabled_warehouses'] > $companyLimits['max_warehouses_enabled'],
                'cash_registers' => $usage['enabled_cash_registers'] > $companyLimits['max_cash_registers_enabled'],
                'companies' => $usage['enabled_companies'] > $platformLimits['max_companies_enabled'],
            ],
        ]);
    }

    public function updateOperationalLimits(UpdateOperationalLimitsRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$this->operationalLimitsService->hasRequiredTables()) {
            return response()->json([
                'message' => 'Operational limits tables not found. Execute SQL incremental first.',
                'required_script' => 'docs/reingenieria/multialmacen_multicaja_limits_20260310.sql',
            ], 409);
        }

        $payload = $request->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $this->operationalLimitsService->updateLimits($companyId, $payload, (int) $authUser->id);

        $usage = $this->operationalLimitsService->getUsage($companyId);
        $companyLimits = $this->operationalLimitsService->getCompanyLimits($companyId);
        $platformLimits = $this->operationalLimitsService->getPlatformLimits();

        return response()->json([
            'message' => 'Operational limits updated',
            'company_id' => $companyId,
            'platform_limits' => $platformLimits,
            'company_limits' => $companyLimits,
            'usage' => $usage,
        ]);
    }

    public function commerceSettings(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id');

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null) {
            $branchExists = $this->operationalContextService->branchExists($companyId, $branchId);

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 403);
            }
        }

        // Use optimized service (2 queries, Redis cached)
        return response()->json($this->featureConfigService->getCommerceSettings($companyId, $branchId));
    }

    public function updateCommerceSettings(UpdateCommerceSettingsRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null) {
            $branchExists = $this->operationalContextService->branchExists($companyId, $branchId);

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 403);
            }
        }

        // Enforce superadmin-only feature codes: only SUPERADMIN / SUPER_ADMIN may
        // write these codes.  Regular company admins are blocked at API level even
        // if they bypass the POS UI.
        $superadminOnlyCodes = array_map(
            'strtoupper',
            config('features.superadmin_only_feature_codes', [])
        );

        if (!empty($superadminOnlyCodes)) {
            $callerRoleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
            $isSuperAdmin = in_array($callerRoleCode, ['SUPERADMIN', 'SUPER_ADMIN'], true);

            if (!$isSuperAdmin) {
                $existingSettings = $this->featureConfigService->getCommerceSettings($companyId, $branchId);
                $existingByCode = collect($existingSettings['features'] ?? [])->keyBy(function ($feature) {
                    return strtoupper(trim((string) ($feature['feature_code'] ?? '')));
                });

                // Non-superadmin can update config fields, but must not alter the
                // enable/disable state of superadmin-only feature flags.
                // Keep persisted state to avoid false 403 when payload includes stale
                // values while saving only tax account/config data.
                foreach ($payload['features'] as &$feature) {
                    $featureCode = strtoupper(trim((string) ($feature['feature_code'] ?? '')));
                    if ($featureCode === '' || !in_array($featureCode, $superadminOnlyCodes, true)) {
                        continue;
                    }

                    $feature['is_enabled'] = (bool) data_get($existingByCode->get($featureCode), 'is_enabled', false);
                }
                unset($feature);
            }
        }

        // Use optimized service (config merge + cache invalidation)
        $result = $this->featureConfigService->updateCommerceSettings($companyId, $branchId, $payload['features'], $authUser->id);

        return response()->json($result);
    }

    private function decodeJsonConfig($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    private function defaultSalesTaxBridgeConfig(): array
    {
        return [
            'bridge_mode' => 'BETA',
            'production_url' => 'https://mundosoftperu.com/MUNDOSOFTPERUSUNAT',
            'beta_url' => 'https://mundosoftperu.com/MUNDOSOFTPERUSUNATBETA',
            'timeout_seconds' => 15,
            'auth_scheme' => 'none',
            'token' => '',
            'force_async_on_issue' => true,
            'auto_send_on_issue' => true,
            'auto_reconcile_enabled' => true,
            'reconcile_batch_size' => 20,
            'reconcile_retry_base_minutes' => 1,
            'reconcile_retry_max_minutes' => 120,
            'reconcile_warn_attempts' => 8,
            'sunat_exception_notify_enabled' => true,
            'sunat_exception_notify_hours' => 6,
            'sunat_alert_repeat_minutes' => 60,
            'sunat_exception_notify_limit' => 120,
            'sol_user' => 'MODDATOS',
            'sol_pass' => 'Moddatos',
            'envio_pse' => '',
        ];
    }

    private function normalizeSunatReconcileAdminConfig(array $config): array
    {
        $defaults = [
            'auto_reconcile_enabled' => true,
            'reconcile_batch_size' => 20,
            'reconcile_retry_base_minutes' => 1,
            'reconcile_retry_max_minutes' => 120,
            'reconcile_warn_attempts' => 8,
            'sunat_exception_notify_enabled' => true,
            'sunat_exception_notify_hours' => 6,
            'sunat_alert_repeat_minutes' => 60,
            'sunat_exception_notify_limit' => 120,
        ];

        $baseMinutes = max(1, min(180, (int) ($config['reconcile_retry_base_minutes'] ?? $defaults['reconcile_retry_base_minutes'])));
        $maxMinutes = max(5, min(1440, (int) ($config['reconcile_retry_max_minutes'] ?? $defaults['reconcile_retry_max_minutes'])));
        if ($maxMinutes < $baseMinutes) {
            $maxMinutes = $baseMinutes;
        }

        return [
            'auto_reconcile_enabled' => isset($config['auto_reconcile_enabled'])
                ? (bool) $config['auto_reconcile_enabled']
                : $defaults['auto_reconcile_enabled'],
            'reconcile_batch_size' => max(5, min(200, (int) ($config['reconcile_batch_size'] ?? $defaults['reconcile_batch_size']))),
            'reconcile_retry_base_minutes' => $baseMinutes,
            'reconcile_retry_max_minutes' => $maxMinutes,
            'reconcile_warn_attempts' => max(1, min(50, (int) ($config['reconcile_warn_attempts'] ?? $defaults['reconcile_warn_attempts']))),
            'sunat_exception_notify_enabled' => isset($config['sunat_exception_notify_enabled'])
                ? (bool) $config['sunat_exception_notify_enabled']
                : $defaults['sunat_exception_notify_enabled'],
            'sunat_exception_notify_hours' => max(1, min(168, (int) ($config['sunat_exception_notify_hours'] ?? $defaults['sunat_exception_notify_hours']))),
            'sunat_alert_repeat_minutes' => max(10, min(1440, (int) ($config['sunat_alert_repeat_minutes'] ?? $defaults['sunat_alert_repeat_minutes']))),
            'sunat_exception_notify_limit' => max(1, min(500, (int) ($config['sunat_exception_notify_limit'] ?? $defaults['sunat_exception_notify_limit']))),
        ];
    }

    private function encodeJsonConfig($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded);
            }

            return json_encode($value);
        }

        return json_encode($value);
    }

    private function resolveVerticalFeaturePreference(int $companyId, string $featureCode): array
    {
        $normalizedCode = strtoupper(trim($featureCode));
        $cacheKey = $companyId . ':' . $normalizedCode;
        if (array_key_exists($cacheKey, $this->verticalFeaturePreferenceCache)) {
            return $this->verticalFeaturePreferenceCache[$cacheKey];
        }

        $default = [
            'resolved' => false,
            'is_enabled' => null,
            'config' => null,
            'source' => null,
        ];

        // Superadmin-only feature codes must never be overridden by vertical templates.
        // Their value is exclusively managed from the Admin Portal.
        $superadminOnly = array_map('strtoupper', config('features.superadmin_only_feature_codes', []));
        if (in_array($normalizedCode, $superadminOnly, true)) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $resolved = $this->verticalFeaturePreferenceService->resolve($companyId, $featureCode);
        if ($resolved['config'] !== null) {
            $resolved['config'] = $this->decodeJsonConfig($resolved['config']);
        }

        if ($resolved['resolved']) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
        return $default;
    }

    private function resolveActiveCompanyVertical(int $companyId): ?array
    {
        if (array_key_exists($companyId, $this->activeVerticalCache)) {
            return $this->activeVerticalCache[$companyId];
        }

        $resolved = $this->operationalLimitsService->resolveActiveCompanyVertical($companyId);
        if ($resolved === null) {
            $this->activeVerticalCache[$companyId] = null;
            return null;
        }

        $this->activeVerticalCache[$companyId] = $resolved;
        return $resolved;
    }

    private function fetchPlatformLimits(): array
    {
        return $this->operationalLimitsService->getPlatformLimits();
    }

    private function fetchCompanyOperationalLimits(int $companyId): array
    {
        return $this->operationalLimitsService->getCompanyLimits($companyId);
    }

    private function fetchCompanyOperationalUsage(int $companyId): array
    {
        return $this->operationalLimitsService->getUsage($companyId);
    }

    private function resolveAuthenticatedStationContext(Request $request, int $companyId): ?array
    {
        $sessionId = (int) ($request->attributes->get('auth_session_id') ?? 0);
        return $this->stationContextService->resolve($sessionId, $companyId);
    }

    private function tableExists(string $schema, string $table): bool
    {
        return $this->featureLabelService->tableExists($schema, $table);
    }

    private function normalizeLegacyCompanyId(int $companyId): int
    {
        if ($companyId <= 0) {
            return $companyId;
        }

        if ($this->companyProfileService->companyExists($companyId)) {
            return $companyId;
        }

        $legacyMap = [4 => 2, 5 => 3];
        if (!array_key_exists($companyId, $legacyMap)) {
            return $companyId;
        }

        $targetId = (int) $legacyMap[$companyId];
        return $this->companyProfileService->companyExists($targetId) ? $targetId : $companyId;
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        return $this->featureLabelService->columnExists($schema, $table, $column);
    }

    private function resolveFeatureLabels(array $featureCodes): array
    {
        $codes = collect($featureCodes)
            ->filter(function ($code) {
                return is_string($code) && $code !== '';
            })
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $this->ensureFeatureLabelsPersisted($codes->all());

        $labels = [];

        if ($this->tableExists('appcfg', 'feature_labels')) {
            $rows = $this->featureLabelService->getActiveFeatureLabelRows($codes->all(), ['feature_code', 'label_es']);

            foreach ($rows as $row) {
                $code = (string) ($row->feature_code ?? '');
                $label = trim((string) ($row->label_es ?? ''));
                if ($code !== '' && $label !== '' && strcasecmp($label, $code) !== 0) {
                    $labels[$code] = $label;
                }
            }
        }

        foreach ($codes as $code) {
            $code = (string) $code;
            if (!array_key_exists($code, $labels)) {
                $labels[$code] = $this->humanizeFeatureCode($code);
            }
        }

        return $labels;
    }

    private function resolveFeatureCategories(array $featureCodes): array
    {
        $codes = collect($featureCodes)
            ->filter(function ($code) {
                return is_string($code) && $code !== '';
            })
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $categories = [];
        $hasCategoryKey = $this->columnExists('appcfg', 'feature_labels', 'category_key');
        $hasCategoryLabel = $this->columnExists('appcfg', 'feature_labels', 'category_label');

        if ($this->tableExists('appcfg', 'feature_labels') && ($hasCategoryKey || $hasCategoryLabel)) {
            $columns = ['feature_code'];
            if ($hasCategoryKey) {
                $columns[] = 'category_key';
            }
            if ($hasCategoryLabel) {
                $columns[] = 'category_label';
            }

            $rows = $this->featureLabelService->getActiveFeatureLabelRows($codes->all(), $columns);

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

        foreach ($codes as $code) {
            $code = (string) $code;
            if (!array_key_exists($code, $categories)) {
                $key = $this->deriveFeatureCategoryKey($code);
                $categories[$code] = [
                    'key' => $key,
                    'label' => $this->humanizeCategoryKey($key),
                ];
            }
        }

        return $categories;
    }

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

        $existing = $this->featureLabelService->getFeatureLabelRows($codes->all(), $columns)
            ->keyBy('feature_code');

        foreach ($codes as $code) {
            $fallbackLabel = $this->humanizeFeatureCode((string) $code);

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
                $values['label_es'] = $fallbackLabel;
                $values['description'] = $fallbackLabel;
            }

            if ($hasCategoryKey) {
                $values['category_key'] = $categoryKey;
            }
            if ($hasCategoryLabel) {
                $values['category_label'] = $categoryLabel;
            }

            $this->featureLabelService->upsertFeatureLabel((string) $code, $values);
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

        return Str::title(Str::lower(trim($humanized)));
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
        $englishAuto = Str::title(Str::lower(trim($humanized)));

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

        return Str::title(str_replace('_', ' ', $normalized));
    }

    private function ensureCompanyAccessLinksForCompanies($companies): void
    {
        if (!$this->companyAccessLinkService->tableExists('appcfg', 'company_access_links')) {
            return;
        }

        foreach ($companies as $company) {
            $companyId = (int) $company->id;
            $exists = $this->companyAccessLinkService->existsByCompanyId($companyId);

            if ($exists) {
                continue;
            }

            $this->ensureCompanyAccessLink(
                $companyId,
                (string) ($company->legal_name ?? ''),
                $company->tax_id !== null ? (string) $company->tax_id : null,
                null
            );
        }
    }

    private function ensureCompanyAccessLink(int $companyId, string $legalName, ?string $taxId, ?int $actorId): string
    {
        return $this->companyAccessLinkService->ensureCompanyAccessLink($companyId, $legalName, $taxId, $actorId);
    }

    private function resolveFrontendAppUrl(): string
    {
        $base = trim((string) env('FRONTEND_ACCESS_URL', ''));
        if ($base === '') {
            $base = trim((string) env('FRONTEND_URL', ''));
        }
        if ($base === '') {
            $base = trim((string) env('FRONTEND_APP_URL', ''));
        }
        if ($base === '') {
            $base = 'http://127.0.0.1:5173';
        }

        $request = request();
        $requestHost = strtolower((string) $request->getHost());
        $originHost = strtolower((string) parse_url((string) $request->headers->get('Origin', ''), PHP_URL_HOST));
        $refererHost = strtolower((string) parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST));

        if (
            $this->isFyctiHost($requestHost) ||
            $this->isFyctiHost($originHost) ||
            $this->isFyctiHost($refererHost)
        ) {
            return 'https://www.fycticonsulting.com';
        }

        $parsed = parse_url($base);
        if (is_array($parsed) && ($parsed['host'] ?? '') === '0.0.0.0') {
            $fallbackHost = request()->getHost() ?: '127.0.0.1';
            $scheme = $parsed['scheme'] ?? (request()->getScheme() ?: 'http');
            $port = isset($parsed['port']) ? ':' . (string) $parsed['port'] : '';
            $path = isset($parsed['path']) ? (string) $parsed['path'] : '';

            return rtrim($scheme . '://' . $fallbackHost . $port . $path, '/');
        }

        return rtrim($base, '/');
    }

    private function isFyctiHost(string $host): bool
    {
        $host = trim(strtolower($host));
        if ($host === '') {
            return false;
        }

        return $host === 'www.fycticonsulting.com'
            || $host === 'admin.fycticonsulting.com'
            || $host === 'api.fycticonsulting.com'
            || str_ends_with($host, '.fycticonsulting.com');
    }

    private function buildCompanyAccessUrl(string $slug): string
    {
        return $this->resolveFrontendAppUrl() . '/t/' . rawurlencode($slug);
    }

    private function companyRateLimitPresets(int $defaultRead, int $defaultWrite, int $defaultReports): array
    {
        return [
            [
                'code' => 'BASIC',
                'name' => 'Basic',
                'requests_per_minute_read' => max(1000, (int) round($defaultRead * 0.65)),
                'requests_per_minute_write' => max(700, (int) round($defaultWrite * 0.6)),
                'requests_per_minute_reports' => max(300, (int) round($defaultReports * 0.55)),
            ],
            [
                'code' => 'PRO',
                'name' => 'Pro',
                'requests_per_minute_read' => $defaultRead,
                'requests_per_minute_write' => $defaultWrite,
                'requests_per_minute_reports' => $defaultReports,
            ],
            [
                'code' => 'ENTERPRISE',
                'name' => 'Enterprise',
                'requests_per_minute_read' => max($defaultRead, 6000),
                'requests_per_minute_write' => max($defaultWrite, 4000),
                'requests_per_minute_reports' => max($defaultReports, 1500),
            ],
        ];
    }

    private function logCompanyRateLimitAudit(
        int $companyId,
        string $actionType,
        string $planCode,
        ?string $presetCode,
        bool $isEnabled,
        int $readPerMinute,
        int $writePerMinute,
        int $reportsPerMinute,
        ?int $appliedBy
    ): void {
        if (!$this->tableExists('appcfg', 'company_rate_limit_audit')) {
            return;
        }

        $this->operationalLimitsService->logCompanyRateLimitAudit([
            'company_id' => $companyId,
            'action_type' => $actionType,
            'plan_code' => $planCode,
            'preset_code' => $presetCode,
            'is_enabled' => $isEnabled,
            'requests_per_minute_read' => $readPerMinute,
            'requests_per_minute_write' => $writePerMinute,
            'requests_per_minute_reports' => $reportsPerMinute,
            'applied_by' => $appliedBy,
            'created_at' => now(),
        ]);
    }
    // ─────────────────────────────────────────────────────────
    // Perfil de empresa
    // ─────────────────────────────────────────────────────────

    public function companyProfile(Request $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $company = $this->companyProfileService->findCompanyProfileRow($companyId);

        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $settings = null;
        if ($this->tableExists('core', 'company_settings')) {
            $settings = $this->companyProfileService->findLatestSettings(
                $companyId,
                $this->columnExists('core', 'company_settings', 'logo_path'),
                $this->columnExists('core', 'company_settings', 'updated_at'),
                $this->columnExists('core', 'company_settings', 'created_at')
            );
        }

        // Extract location fields from extra_data
        $extraData = $settings
            ? json_decode((string) ($settings->extra_data ?? '{}'), true) ?? []
            : [];

        $logoPath = $settings->logo_path ?? null;
        $logoNormalizedPath = $this->normalizeCompanyLogoStoragePath($logoPath);
        $logoExistsInStorage = $logoNormalizedPath ? $this->publicStorageLogoExists($logoNormalizedPath) : false;

        $logoDataUri = null;
        if (isset($extraData['company_logo_data_uri'])) {
            $candidateDataUri = trim((string) $extraData['company_logo_data_uri']);
            if (preg_match('/^data:image\//i', $candidateDataUri) === 1) {
                $logoDataUri = $candidateDataUri;
            }
        }

        if ($logoDataUri === null && $logoNormalizedPath && $logoExistsInStorage) {
            $generatedDataUri = $this->companyLogoDataUriFromPublicStorage($logoNormalizedPath);
            if ($generatedDataUri !== null) {
                $logoDataUri = $generatedDataUri;
                $extraData['company_logo_data_uri'] = $generatedDataUri;

                if ($settings && $this->tableExists('core', 'company_settings')) {
                    $settingsUpdates = ['extra_data' => json_encode($extraData)];
                    if ($this->columnExists('core', 'company_settings', 'updated_at')) {
                        $settingsUpdates['updated_at'] = now();
                    }

                    $this->companyProfileService->updateCompanySettings($companyId, $settingsUpdates);
                }
            }
        }

        $logoUrl = $logoExistsInStorage
            ? $this->resolveCompanyLogoUrl($logoPath)
            : null;

        if (($logoUrl === null || $logoUrl === '') && $logoDataUri !== null) {
            $logoUrl = $logoDataUri;
        }

        if (($logoUrl === null || $logoUrl === '') && $logoPath !== null) {
            $logoUrl = $this->resolveCompanyLogoUrl($logoPath);
        }

        return response()->json([
            'company_id'      => $companyId,
            'tax_id'          => $company->tax_id,
            'legal_name'      => $company->legal_name,
            'trade_name'      => $company->trade_name,
            'status'          => (int) $company->status,
            'address'         => $settings->address    ?? null,
            'phone'           => $settings->phone       ?? null,
            'telefono_movil'  => $extraData['telefono_movil'] ?? null,
            'telefono_fijo'   => $extraData['telefono_fijo'] ?? null,
            'company_description' => $extraData['company_description'] ?? null,
            'email'           => $settings->email       ?? null,
            'website'         => $settings->website     ?? null,
            'ubigeo'          => $extraData['ubigeo'] ?? null,
            'departamento'    => $extraData['departamento'] ?? null,
            'provincia'       => $extraData['provincia'] ?? null,
            'distrito'        => $extraData['distrito'] ?? null,
            'urbanizacion'    => $extraData['urbanizacion'] ?? null,
            'sunat_secondary_user' => $extraData['sunat_secondary_user'] ?? null,
            'sunat_secondary_pass' => $extraData['sunat_secondary_pass'] ?? null,
            'client_id'       => $extraData['client_id'] ?? null,
            'client_secret'   => $extraData['client_secret'] ?? null,
            'show_payment_brand_icons' => array_key_exists('show_payment_brand_icons', $extraData)
                ? filter_var($extraData['show_payment_brand_icons'], FILTER_VALIDATE_BOOLEAN)
                : true,
            'logo_url'        => $logoUrl,
            'has_cert'        => $settings && !empty($settings->cert_path),
            'bank_accounts'   => $settings
                ? json_decode((string) $settings->bank_accounts, true) ?? []
                : [],
        ]);
    }

    public function updateCompanyProfile(UpdateCompanyProfileRequest $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser = $request->attributes->get('auth_user');
        $payload   = $request->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        // Actualizar datos basicos de la empresa
        $companyUpdates = array_filter([
            'tax_id'     => $payload['tax_id']     ?? null,
            'legal_name' => $payload['legal_name'] ?? null,
            'trade_name' => $payload['trade_name'] ?? null,
        ], fn($v) => $v !== null);

        if (!empty($companyUpdates)) {
            $this->companyProfileService->updateCompanyBasic($companyId, $companyUpdates);
        }

        // Actualizar configuracion extendida si la tabla existe
        if ($this->tableExists('core', 'company_settings')) {
            $hasCreatedAt = $this->columnExists('core', 'company_settings', 'created_at');
            $currentSettings = $this->companyProfileService->findLatestSettings(
                $companyId,
                $this->columnExists('core', 'company_settings', 'logo_path'),
                $this->columnExists('core', 'company_settings', 'updated_at'),
                $hasCreatedAt
            );

            $settingsUpdates = [
                'updated_at' => now(),
            ];
            if (array_key_exists('address', $payload)) {
                $settingsUpdates['address'] = $payload['address'];
            }
            if (array_key_exists('phone', $payload)) {
                $settingsUpdates['phone'] = $payload['phone'];
            }
            if (array_key_exists('email', $payload)) {
                $settingsUpdates['email'] = $payload['email'];
            }
            if (array_key_exists('website', $payload)) {
                $settingsUpdates['website'] = $payload['website'];
            }
            if (array_key_exists('bank_accounts', $payload)) {
                $settingsUpdates['bank_accounts'] = json_encode($payload['bank_accounts'] ?? []);
            }

            $extraDataFields = ['ubigeo', 'departamento', 'provincia', 'distrito', 'urbanizacion', 'telefono_movil', 'telefono_fijo', 'company_description', 'sunat_secondary_user', 'sunat_secondary_pass', 'client_id', 'client_secret', 'show_payment_brand_icons'];
            $hasExtraDataUpdates = false;
            foreach ($extraDataFields as $field) {
                if (array_key_exists($field, $payload)) {
                    $hasExtraDataUpdates = true;
                    break;
                }
            }

            if ($hasExtraDataUpdates) {
                $currentExtra = $currentSettings
                    ? json_decode((string) ($currentSettings->extra_data ?? '{}'), true) ?? []
                    : [];

                foreach ($extraDataFields as $field) {
                    if (array_key_exists($field, $payload)) {
                        if ($payload[$field] === null || $payload[$field] === '') {
                            unset($currentExtra[$field]);
                        } else {
                            $currentExtra[$field] = $field === 'show_payment_brand_icons'
                                ? (bool) $payload[$field]
                                : $payload[$field];
                        }
                    }
                }

                $settingsUpdates['extra_data'] = json_encode($currentExtra);
            }

            if ($currentSettings && !empty($currentSettings->logo_path) && !array_key_exists('logo_path', $settingsUpdates)) {
                $settingsUpdates['logo_path'] = $currentSettings->logo_path;
            }

            $this->companyProfileService->upsertCompanySettings($companyId, $settingsUpdates, $hasCreatedAt);
        }

        return $this->companyProfile($request, $companyIgvRateService);
    }

    public function igvSettings(Request $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        return response()->json([
            'company_id' => $companyId,
            'active_rate' => $companyIgvRateService->resolveActiveRate($companyId),
        ]);
    }

    public function updateIgvSettings(UpdateIgvSettingsRequest $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $activeRate = $companyIgvRateService->setActiveRatePercent($companyId, (float) $payload['active_igv_rate_percent']);

        return response()->json([
            'company_id' => $companyId,
            'active_rate' => $activeRate,
        ]);
    }

    public function uploadCompanyLogo(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id', $authUser->company_id));

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$request->hasFile('logo')) {
            return response()->json(['message' => 'No se recibio el archivo logo'], 422);
        }

        $file = $request->file('logo');
        if (!$file || !$file->isValid()) {
            return response()->json(['message' => 'El archivo logo es invalido o esta corrupto'], 422);
        }

        // Validar MIME y tamaño (max 2 MB)
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return response()->json(['message' => 'Tipo de archivo no permitido. Use JPG, PNG, GIF o WEBP'], 422);
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return response()->json(['message' => 'El logo no puede superar 2 MB'], 422);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === '') {
            $mime = strtolower((string) $file->getMimeType());
            $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/gif' ? 'gif' : ($mime === 'image/webp' ? 'webp' : 'jpg'));
        }

        $path = "logos/company_{$companyId}.{$ext}";
        $logoBinary = @file_get_contents($file->getRealPath());
        $logoMime = strtolower((string) $file->getMimeType());
        if ($logoMime === '') {
            $logoMime = in_array($ext, ['jpg', 'jpeg'], true)
                ? 'image/jpeg'
                : ($ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/webp'));
        }

        $logoDataUri = null;
        if ($logoBinary !== false && $logoBinary !== '') {
            $logoDataUri = 'data:' . $logoMime . ';base64,' . base64_encode($logoBinary);
        }

        try {
            Storage::disk('public')->putFileAs('logos', $file, "company_{$companyId}.{$ext}");
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo guardar el logo en almacenamiento publico'], 500);
        }

        if ($this->tableExists('core', 'company_settings')) {
            $settingsUpdates = ['logo_path' => $path, 'updated_at' => now()];

            $hasCreatedAt = $this->columnExists('core', 'company_settings', 'created_at');
            $currentSettings = $this->companyProfileService->findLatestSettings(
                $companyId,
                false,
                $this->columnExists('core', 'company_settings', 'updated_at'),
                $hasCreatedAt
            );

            $currentExtra = $currentSettings
                ? json_decode((string) ($currentSettings->extra_data ?? '{}'), true) ?? []
                : [];

            if ($logoDataUri !== null) {
                $currentExtra['company_logo_data_uri'] = $logoDataUri;
            }

            if (!empty($currentExtra)) {
                $settingsUpdates['extra_data'] = json_encode($currentExtra);
            }

            $this->companyProfileService->upsertCompanySettings($companyId, $settingsUpdates, $hasCreatedAt);
        }

        return response()->json([
            'message'  => 'Logo actualizado',
            'logo_url' => '/storage/' . ltrim((string) $path, '/'),
        ]);
    }

    public function uploadCompanyCert(Request $request, TaxBridgeService $taxBridgeService)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id', $authUser->company_id));

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$request->hasFile('cert')) {
            return response()->json(['message' => 'No se recibio el certificado'], 422);
        }

        $certPassword = $request->input('cert_password', '');
        if ($certPassword === '') {
            return response()->json(['message' => 'La contrasena del certificado es requerida'], 422);
        }

        $file = $request->file('cert');

        // Validar extension (.p12, .pfx, .pem)
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['p12', 'pfx', 'pem'], true)) {
            return response()->json(['message' => 'Solo se aceptan certificados .p12, .pfx o .pem'], 422);
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return response()->json(['message' => 'El certificado no puede superar 2 MB'], 422);
        }

        // Almacenar en disco LOCAL (nunca publico)
        $certPath = "certs/company_{$companyId}.{$ext}";
        Storage::disk('local')->put($certPath, file_get_contents($file->getRealPath()));

        // Cifrar contrasena
        $encPassword = Crypt::encryptString($certPassword);

        if ($this->tableExists('core', 'company_settings')) {
            $hasCreatedAt = $this->columnExists('core', 'company_settings', 'created_at');
            $settingsUpdates = [
                'cert_path'         => $certPath,
                'cert_password_enc' => $encPassword,
                'updated_at'        => now(),
            ];

            $this->companyProfileService->upsertCompanySettings($companyId, $settingsUpdates, $hasCreatedAt);
        }

        $company = $this->companyProfileService->findCompanyForBridgePayload($companyId);

        $settings = null;
        if ($this->tableExists('core', 'company_settings')) {
            $settings = $this->companyProfileService->findBridgeSettings($companyId);
        }

        $extraData = $settings && $settings->extra_data
            ? (json_decode((string) $settings->extra_data, true) ?: [])
            : [];

        $companyPhone = (string) ($settings->phone ?? '');
        $companyMobilePhone = (string) (($extraData['telefono_movil'] ?? $extraData['mobile_phone'] ?? '') ?: $companyPhone);
        $companyLandlinePhone = (string) (($extraData['telefono_fijo'] ?? $extraData['landline_phone'] ?? '') ?: $companyPhone);

        $bridgePayload = [
            'empresa' => (string) ($company->legal_name ?? ''),
            'nomcom' => (string) ($company->trade_name ?? ''),
            'ruc' => (string) ($company->tax_id ?? ''),
            'domicilio_fiscal' => (string) ($settings->address ?? ''),
            'dep' => (string) ($extraData['departamento'] ?? ''),
            'pro' => (string) ($extraData['provincia'] ?? ''),
            'dis' => (string) ($extraData['distrito'] ?? ''),
            'urb' => (string) ($extraData['urbanizacion'] ?? ''),
            'ubigeo' => (string) ($extraData['ubigeo'] ?? ''),
            'correo' => (string) ($settings->email ?? ''),
            'telefono_movil' => $companyMobilePhone,
            'telefono_fijo' => $companyLandlinePhone,
            'user' => (string) ($extraData['sunat_secondary_user'] ?? ''),
            'pass' => (string) ($extraData['sunat_secondary_pass'] ?? ''),
            'pass_certificado' => $certPassword,
        ];

        try {
            $bridgeResult = $taxBridgeService->registerCertificate(
                $companyId,
                null,
                $bridgePayload,
                $file->getRealPath(),
                $file->getClientOriginalName()
            );
        } catch (TaxBridgeException $e) {
            return response()->json([
                'message' => 'Certificado guardado localmente, pero no se pudo registrar en el puente: ' . $e->getMessage(),
                'has_cert' => true,
            ], 422);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'Certificado guardado localmente, pero el puente no esta disponible por red/DNS. Reintenta cuando haya conectividad.',
                'has_cert' => true,
                'bridge_error' => substr($e->getMessage(), 0, 500),
                'bridge_retry_recommended' => true,
            ], 503);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Certificado guardado localmente, pero ocurrio un error al registrar en el puente',
                'has_cert' => true,
                'bridge_error' => substr($e->getMessage(), 0, 500),
            ], 500);
        }

        $legacyCode = $bridgeResult['legacy_code'] ?? null;

        if ($legacyCode === 0) {
            return response()->json([
                'message' => 'Clave del certificado incorrecta',
                'has_cert' => true,
                'bridge_debug' => [
                    'endpoint' => $bridgeResult['endpoint'] ?? '',
                    'method' => 'POST',
                    'payload' => $bridgeResult['payload'] ?? [],
                ],
                'bridge_response' => $bridgeResult['json_response'] ?? ($bridgeResult['raw_response'] ?? null),
            ], 422);
        }

        if ($legacyCode !== 1 && $legacyCode !== 3) {
            return response()->json([
                'message' => 'Certificado guardado localmente, pero el puente devolvio una respuesta no esperada',
                'has_cert' => true,
                'bridge_debug' => [
                    'endpoint' => $bridgeResult['endpoint'] ?? '',
                    'method' => 'POST',
                    'payload' => $bridgeResult['payload'] ?? [],
                ],
                'bridge_response' => $bridgeResult['json_response'] ?? ($bridgeResult['raw_response'] ?? null),
            ], 502);
        }

        return response()->json([
            'message'  => 'Certificado digital actualizado y registrado en puente',
            'has_cert' => true,
            'bridge_debug' => [
                'endpoint' => $bridgeResult['endpoint'] ?? '',
                'method' => 'POST',
                'payload' => $bridgeResult['payload'] ?? [],
            ],
            'bridge_response' => $bridgeResult['json_response'] ?? ($bridgeResult['raw_response'] ?? null),
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin-only: per-company commerce features matrix
    // -------------------------------------------------------------------------

    public function companyCommerceAdminMatrix(Request $request)
    {
        $ADMIN_FEATURE_CODES = self::ADMIN_COMMERCE_FEATURE_CODES;
        $this->ensureFeatureLabelsPersisted($ADMIN_FEATURE_CODES);
        $labelsByCode = $this->resolveFeatureLabels($ADMIN_FEATURE_CODES);
        $categoriesByCode = $this->resolveFeatureCategories($ADMIN_FEATURE_CODES);

        $companies = $this->adminSettingsMatrixService->listNonSystemCompanies(self::SYSTEM_COMPANY_ID);

        $allToggles = $this->adminSettingsMatrixService->getFeatureTogglesByCodes($ADMIN_FEATURE_CODES);

        $rows = $companies->map(function ($company) use ($ADMIN_FEATURE_CODES, $allToggles) {
            $companyId = (int) $company->id;
            $togglesByCode = ($allToggles->get($companyId) ?? collect())->keyBy('feature_code');

            $features = [];
            foreach ($ADMIN_FEATURE_CODES as $code) {
                $toggle = $togglesByCode->get($code);
                $features[$code] = $toggle ? (bool) $toggle->is_enabled : false;
            }

            return [
                'company_id' => $companyId,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'features' => $features,
            ];
        })->values();

        return response()->json([
            'feature_codes' => $ADMIN_FEATURE_CODES,
            'feature_labels' => $labelsByCode,
            'feature_categories' => $categoriesByCode,
            'companies' => $rows,
        ]);
    }

    public function updateCompanyCommerceAdminMatrix(UpdateCompanyCommerceAdminMatrixRequest $request)
    {
        $ADMIN_FEATURE_CODES = self::ADMIN_COMMERCE_FEATURE_CODES;

        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyId = $this->normalizeLegacyCompanyId((int) $payload['company_id']);

        $companyExists = $this->adminSettingsMatrixService->companyExists($companyId);
        if (!$companyExists) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $hasCreatedAt = $this->columnExists('appcfg', 'company_feature_toggles', 'created_at');

        $valuesByCode = [];
        foreach ($ADMIN_FEATURE_CODES as $code) {
            if (!array_key_exists($code, $payload['features'])) {
                continue;
            }

            $valuesByCode[$code] = [
                'is_enabled' => (bool) $payload['features'][$code],
                'updated_by' => $authUser ? $authUser->id : null,
                'updated_at' => now(),
            ];

            if ($hasCreatedAt) {
                $valuesByCode[$code]['created_at'] = now();
            }
        }

        $this->adminSettingsMatrixService->upsertCompanyFeatureTogglesBulk($companyId, $valuesByCode);

        // Invalidate feature config cache for this company.
        $this->featureConfigService->invalidateCache($companyId, null);

        return $this->companyCommerceAdminMatrix($request);
    }

    // -------------------------------------------------------------------------
    // Admin-only: per-company SUNAT reconcile matrix
    // -------------------------------------------------------------------------

    public function companySunatReconcileAdminMatrix(Request $request)
    {
        $companies = $this->adminSettingsMatrixService->listNonSystemCompanies(self::SYSTEM_COMPANY_ID);

        $rowsByCompany = $this->adminSettingsMatrixService->getFeatureTogglesByCode(self::SALES_TAX_BRIDGE_FEATURE_CODE);

        $defaults = $this->normalizeSunatReconcileAdminConfig($this->defaultSalesTaxBridgeConfig());

        $rows = $companies->map(function ($company) use ($rowsByCompany, $defaults) {
            $companyId = (int) $company->id;
            $toggleRow = $rowsByCompany->get($companyId);
            $rawConfig = $this->decodeJsonConfig($toggleRow->config ?? null);
            $config = is_array($rawConfig) ? $rawConfig : [];
            $normalized = $this->normalizeSunatReconcileAdminConfig($config);

            return [
                'company_id' => $companyId,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'tax_bridge_enabled' => $toggleRow ? (bool) $toggleRow->is_enabled : true,
                'sunat_reconcile' => [
                    'auto_reconcile_enabled' => (bool) ($normalized['auto_reconcile_enabled'] ?? $defaults['auto_reconcile_enabled']),
                    'reconcile_batch_size' => (int) ($normalized['reconcile_batch_size'] ?? $defaults['reconcile_batch_size']),
                    'reconcile_retry_base_minutes' => (int) ($normalized['reconcile_retry_base_minutes'] ?? $defaults['reconcile_retry_base_minutes']),
                    'reconcile_retry_max_minutes' => (int) ($normalized['reconcile_retry_max_minutes'] ?? $defaults['reconcile_retry_max_minutes']),
                    'reconcile_warn_attempts' => (int) ($normalized['reconcile_warn_attempts'] ?? $defaults['reconcile_warn_attempts']),
                    'sunat_exception_notify_enabled' => (bool) ($normalized['sunat_exception_notify_enabled'] ?? $defaults['sunat_exception_notify_enabled']),
                    'sunat_exception_notify_hours' => (int) ($normalized['sunat_exception_notify_hours'] ?? $defaults['sunat_exception_notify_hours']),
                    'sunat_alert_repeat_minutes' => (int) ($normalized['sunat_alert_repeat_minutes'] ?? $defaults['sunat_alert_repeat_minutes']),
                    'sunat_exception_notify_limit' => (int) ($normalized['sunat_exception_notify_limit'] ?? $defaults['sunat_exception_notify_limit']),
                ],
            ];
        })->values();

        return response()->json([
            'defaults' => $defaults,
            'companies' => $rows,
        ]);
    }

    public function updateCompanySunatReconcileAdminMatrix(UpdateCompanySunatReconcileAdminMatrixRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyId = $this->normalizeLegacyCompanyId((int) $payload['company_id']);

        if ($companyId === self::SYSTEM_COMPANY_ID) {
            return response()->json(['message' => 'La empresa del sistema no se administra desde este panel.'], 403);
        }

        $companyExists = $this->adminSettingsMatrixService->companyExists($companyId);
        if (!$companyExists) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $row = $this->adminSettingsMatrixService->getFeatureToggleRow($companyId, self::SALES_TAX_BRIDGE_FEATURE_CODE);

        $currentConfigRaw = $this->decodeJsonConfig($row->config ?? null);
        $currentConfig = is_array($currentConfigRaw) ? $currentConfigRaw : [];

        $fields = [
            'auto_reconcile_enabled',
            'reconcile_batch_size',
            'reconcile_retry_base_minutes',
            'reconcile_retry_max_minutes',
            'reconcile_warn_attempts',
            'sunat_exception_notify_enabled',
            'sunat_exception_notify_hours',
            'sunat_alert_repeat_minutes',
            'sunat_exception_notify_limit',
        ];

        $nextConfigCandidate = $currentConfig;
        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $nextConfigCandidate[$field] = $payload[$field];
            }
        }

        $normalizedConfig = $this->normalizeSunatReconcileAdminConfig($nextConfigCandidate);
        foreach ($normalizedConfig as $key => $value) {
            $currentConfig[$key] = $value;
        }

        $hasCreatedAt = $this->columnExists('appcfg', 'company_feature_toggles', 'created_at');
        $taxBridgeEnabled = array_key_exists('tax_bridge_enabled', $payload)
            ? (bool) $payload['tax_bridge_enabled']
            : ($row ? (bool) $row->is_enabled : true);

        $values = [
            'is_enabled' => $taxBridgeEnabled,
            'config' => $this->encodeJsonConfig($currentConfig),
            'updated_by' => $authUser ? $authUser->id : null,
            'updated_at' => now(),
        ];
        if ($hasCreatedAt) {
            $values['created_at'] = now();
        }

        $this->adminSettingsMatrixService->upsertCompanyFeatureToggle(
            $companyId,
            self::SALES_TAX_BRIDGE_FEATURE_CODE,
            $values
        );

        return $this->companySunatReconcileAdminMatrix($request);
    }

    // -------------------------------------------------------------------------
    // Admin-only: per-company inventory settings matrix
    // -------------------------------------------------------------------------

    public function companyInventorySettingsAdminMatrix(Request $request)
    {
        $companies = $this->adminSettingsMatrixService->listNonSystemCompanies(self::SYSTEM_COMPANY_ID);

        $inventorySettingsByCompany = collect();
        if ($this->tableExists('inventory', 'inventory_settings')) {
            $inventorySettingsByCompany = $this->adminSettingsMatrixService->getInventorySettingsByCompany();
        }

        $rows = $companies->map(function ($company) use ($inventorySettingsByCompany) {
            $companyId = (int) $company->id;
            $settings = $inventorySettingsByCompany->get($companyId);

            return [
                'company_id' => $companyId,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'inventory_settings' => [
                    'complexity_mode' => $settings ? ($settings->complexity_mode ?? 'BASIC') : 'BASIC',
                    'inventory_mode' => $settings ? ($settings->inventory_mode ?? 'KARDEX_SIMPLE') : 'KARDEX_SIMPLE',
                    'lot_outflow_strategy' => $settings ? ($settings->lot_outflow_strategy ?? 'MANUAL') : 'MANUAL',
                    'enable_inventory_pro' => $settings ? (bool) $settings->enable_inventory_pro : false,
                    'enable_lot_tracking' => $settings ? (bool) $settings->enable_lot_tracking : false,
                    'enable_expiry_tracking' => $settings ? (bool) $settings->enable_expiry_tracking : false,
                    'enable_advanced_reporting' => $settings ? (bool) $settings->enable_advanced_reporting : false,
                    'enable_graphical_dashboard' => $settings ? (bool) $settings->enable_graphical_dashboard : false,
                    'enable_location_control' => $settings ? (bool) $settings->enable_location_control : false,
                    'allow_negative_stock' => $settings ? (bool) $settings->allow_negative_stock : false,
                    'low_stock_alert_threshold' => $settings ? (int) ($settings->low_stock_alert_threshold ?? 5) : 5,
                    'enforce_lot_for_tracked' => $settings ? (bool) $settings->enforce_lot_for_tracked : false,
                ],
            ];
        })->values();

        return response()->json(['companies' => $rows]);
    }

    public function updateCompanyInventorySettingsAdminMatrix(UpdateCompanyInventorySettingsAdminMatrixRequest $request)
    {
        if (!$this->tableExists('inventory', 'inventory_settings')) {
            return response()->json(['message' => 'Inventory settings table not found'], 409);
        }

        $hasLowStockAlertThreshold = $this->columnExists('inventory', 'inventory_settings', 'low_stock_alert_threshold');

        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = $this->normalizeLegacyCompanyId((int) $payload['company_id']);

        $companyExists = $this->adminSettingsMatrixService->companyExists($companyId);
        if (!$companyExists) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $hasCreatedAt = $this->columnExists('inventory', 'inventory_settings', 'created_at');

        $updates = ['updated_at' => now()];
        $boolFields = [
            'enable_inventory_pro', 'enable_lot_tracking', 'enable_expiry_tracking',
            'enable_advanced_reporting', 'enable_graphical_dashboard', 'enable_location_control',
            'allow_negative_stock', 'enforce_lot_for_tracked',
        ];
        $strFields = ['complexity_mode', 'inventory_mode', 'lot_outflow_strategy'];
        $intFields = $hasLowStockAlertThreshold ? ['low_stock_alert_threshold'] : [];

        foreach ($boolFields as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = (bool) $payload[$field];
            }
        }
        foreach ($strFields as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }
        foreach ($intFields as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = (int) $payload[$field];
            }
        }

        $this->adminSettingsMatrixService->upsertInventorySettings(
            $companyId,
            array_merge(
                $updates,
                ['company_id' => $companyId],
                $hasCreatedAt ? ['created_at' => now()] : []
            )
        );

        return $this->companyInventorySettingsAdminMatrix($request);
    }

    private function resolveCompanyLogoUrl($logoPath): ?string
    {
        $raw = trim((string) ($logoPath ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $raw) === 1) {
            return $raw;
        }

        $normalized = str_replace('\\', '/', $raw);
        if (preg_match('/^https?:\/\//i', $normalized) === 1) {
            $pathFromUrl = parse_url($normalized, PHP_URL_PATH);
            $normalized = $pathFromUrl !== null ? (string) $pathFromUrl : $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        if ($normalized === '') {
            return null;
        }

        try {
            if (Storage::disk('public')->exists($normalized)) {
                return '/storage/' . $normalized;
            }
        } catch (\Throwable $e) {
            // Si falla la validacion del disco, devolvemos la ruta normalizada para no ocultar el logo.
        }

        return '/storage/' . $normalized;
    }

    private function normalizeCompanyLogoStoragePath($logoPath): ?string
    {
        $raw = trim((string) ($logoPath ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $raw);
        if (preg_match('/^https?:\/\//i', $normalized) === 1) {
            $pathFromUrl = parse_url($normalized, PHP_URL_PATH);
            $normalized = $pathFromUrl !== null ? (string) $pathFromUrl : $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        if (str_starts_with($normalized, 'public/')) {
            $normalized = ltrim(substr($normalized, strlen('public/')), '/');
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function publicStorageLogoExists(string $normalizedPath): bool
    {
        try {
            return Storage::disk('public')->exists($normalizedPath);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function companyLogoDataUriFromPublicStorage(string $normalizedPath): ?string
    {
        try {
            if (!Storage::disk('public')->exists($normalizedPath)) {
                return null;
            }

            $absolutePath = Storage::disk('public')->path($normalizedPath);
            $binary = @file_get_contents($absolutePath);
            if ($binary === false || $binary === '') {
                return null;
            }

            $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
            $mimeType = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => '',
            };

            if ($mimeType === '') {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detected = @finfo_file($finfo, $absolutePath);
                    @finfo_close($finfo);
                    $mimeType = is_string($detected) ? trim($detected) : '';
                }
            }

            if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
                $mimeType = 'image/png';
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($binary);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
