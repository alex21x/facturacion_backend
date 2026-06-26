<?php

namespace App\Services\Sales;

use App\Application\UseCases\Sales\ResolveCompanyPrintProfileUseCase;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Infrastructure\Repositories\Sales\Documents\SalesDocumentSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

class SalesLookupApplicationService
{
    public function __construct(
        private SalesLookupService $salesLookupService,
        private ReferenceDocumentService $referenceDocumentService,
        private CompanyIgvRateService $companyIgvRateService,
        private ResolveCompanyPrintProfileUseCase $resolveCompanyPrintProfileUseCase,
        private SalesDocumentSupportService $supportService
    ) {
    }

    public function bootstrap(Request $request, callable $lookupsResolver): array
    {
        $includeDocuments = filter_var($request->query('include_documents', false), FILTER_VALIDATE_BOOLEAN);
        $lookupsPayload = $lookupsResolver($request);
        $documentsPayload = null;

        if ($includeDocuments) {
            $authUser = $request->attributes->get('auth_user');
            $companyId = (int) $request->attributes->get('resolved_company_id');
            $page = max(1, (int) $request->query('page', 1));
            $limit = max(1, min(200, (int) ($request->query('per_page', $request->query('limit', 10)))));

            $documentsPayload = $this->salesLookupService->paginateCommercialDocuments(
                $companyId,
                $this->buildCommercialDocumentFilters($request, $authUser),
                $page,
                $limit
            );
        }

        return [
            'lookups' => $lookupsPayload,
            'documents' => $documentsPayload,
        ];
    }

    public function buildLookupsPayload(Request $request): array
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id', $authUser->branch_id ?? null);

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        $authBranchId = isset($authUser->branch_id) && $authUser->branch_id !== null
            ? (int) $authUser->branch_id
            : null;

        if ($branchId !== null && $branchId !== $authBranchId && !$this->salesLookupService->branchExists($companyId, $branchId)) {
            return ['error' => response()->json(['message' => 'Invalid branch scope'], 422)];
        }

        $featureToggles = $this->salesLookupService->loadFeatureTogglesForContext($companyId, $branchId);
        $companyFeatureToggles = $featureToggles['company'] ?? collect();
        $branchFeatureToggles = $featureToggles['branch'] ?? collect();

        $currencies = $this->salesLookupService->listActiveCurrencies();
        $paymentMethods = $this->salesLookupService->listActivePaymentTypes();
        $catalog = $this->documentKindCatalog();

        $enabledToggles = $companyFeatureToggles->pluck('is_enabled', 'feature_code');

        $documentKinds = $catalog->filter(function ($row) use ($enabledToggles) {
            $featureCode = 'DOC_KIND_' . (string) ($row['code'] ?? '');
            $tableEnabled = (bool) ($row['is_enabled'] ?? true);

            return $tableEnabled
                && (!$enabledToggles->has($featureCode) || (bool) $enabledToggles->get($featureCode));
        })->values();

        if ($documentKinds->isEmpty()) {
            $documentKinds = $catalog->values();
        }

        $featureDefaults = [
            'SALES_SELLER_TO_CASHIER' => false,
            'SALES_CUSTOMER_PRICE_PROFILE' => false,
            'SALES_WORKSHOP_MULTI_VEHICLE' => false,
            'SALES_ORDER_MULTI_PAYMENT_ENABLED' => false,
            'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' => true,
            'SALES_ANTICIPO_ENABLED' => false,
            'SALES_TAX_BRIDGE' => false,
            'SALES_TAX_BRIDGE_DEBUG_VIEW' => false,
            'SALES_PRINT_SHOW_PRODUCT_CODES' => true,
            'SALES_GLOBAL_DISCOUNT_ENABLED' => false,
            'SALES_ITEM_DISCOUNT_ENABLED' => false,
            'SALES_FREE_ITEMS_ENABLED' => false,
            'SALES_ALLOW_DRAFT_EDIT' => true,
            'SALES_ALLOW_DOCUMENT_VOID' => true,
            'SALES_ALLOW_VOID_FOR_SELLER' => true,
            'SALES_ALLOW_VOID_FOR_CASHIER' => true,
            'SALES_ALLOW_VOID_FOR_ADMIN' => true,
            'SALES_VOID_REQUIRE_PASSWORD' => false,
            'SALES_VOID_REVERSE_STOCK' => true,
            'SALES_BULK_VOID_REPORT_ENABLED' => false,
        ];

        $commerceFeatures = collect(array_keys($featureDefaults))->map(function ($featureCode) use ($companyFeatureToggles, $branchFeatureToggles, $featureDefaults) {
            $resolved = $this->resolveFeatureFromCollections(
                $companyFeatureToggles,
                $branchFeatureToggles,
                (string) $featureCode,
                (bool) ($featureDefaults[$featureCode] ?? false)
            );

            return [
                'feature_code' => $featureCode,
                'is_enabled' => $resolved['is_enabled'],
                'company_enabled' => $resolved['company_enabled'],
                'branch_enabled' => $resolved['branch_enabled'],
                'config' => $resolved['config'],
                'vertical_source' => null,
            ];
        })->values();

        $salesDetraccionResolved = $this->resolveFeatureFromCollections($companyFeatureToggles, $branchFeatureToggles, 'SALES_DETRACCION_ENABLED', false);
        $salesRetencionResolved = $this->resolveFeatureFromCollections($companyFeatureToggles, $branchFeatureToggles, 'SALES_RETENCION_ENABLED', false);
        $salesPercepcionResolved = $this->resolveFeatureFromCollections($companyFeatureToggles, $branchFeatureToggles, 'SALES_PERCEPCION_ENABLED', false);

        $salesDetraccionEnabled = (bool) $salesDetraccionResolved['is_enabled'];
        $salesRetencionEnabled = (bool) $salesRetencionResolved['is_enabled'];
        $salesPercepcionEnabled = (bool) $salesPercepcionResolved['is_enabled'];

        $taxCategories = $this->companyIgvRateService->applyActiveRateToTaxCategories(
            $companyId,
            $this->salesLookupService->resolveTaxCategoriesRows($companyId)
        );

        return [
            'document_kinds' => $documentKinds,
            'currencies' => $currencies,
            'payment_methods' => $paymentMethods,
            'tax_categories' => $taxCategories,
            'active_igv_rate_percent' => $this->companyIgvRateService->resolveActiveRatePercent($companyId),
            'units' => $this->salesLookupService->enabledUnits($companyId),
            'inventory_settings' => $this->normalizeInventorySettings($this->salesLookupService->inventorySettingsForCompany($companyId)),
            'credit_note_reasons' => $this->salesLookupService->resolveDocumentNoteReasonsRows('CREDIT_NOTE'),
            'debit_note_reasons' => $this->salesLookupService->resolveDocumentNoteReasonsRows('DEBIT_NOTE'),
            'detraccion_service_codes' => $salesDetraccionEnabled ? $this->salesLookupService->resolveDetractionServiceCodes() : [],
            'detraccion_min_amount' => $salesDetraccionEnabled ? $this->resolveFeatureMinAmount($salesDetraccionResolved, 700.00) : null,
            'detraccion_account' => $salesDetraccionEnabled ? $this->resolveFeatureAccountInfo($companyId, $salesDetraccionResolved, 'DETRACCION') : null,
            'retencion_types' => $salesRetencionEnabled ? $this->resolveRetencionTypes($salesRetencionResolved) : [],
            'retencion_account' => $salesRetencionEnabled ? $this->resolveFeatureAccountInfo($companyId, $salesRetencionResolved, 'RETENCION') : null,
            'retencion_percentage' => $salesRetencionEnabled ? 3.00 : null,
            'percepcion_types' => $salesPercepcionEnabled ? $this->resolvePercepcionTypes($salesPercepcionResolved) : [],
            'percepcion_account' => $salesPercepcionEnabled ? $this->resolveFeatureAccountInfo($companyId, $salesPercepcionResolved, 'PERCEPCION') : null,
            'sunat_operation_types' => ($salesDetraccionEnabled || $salesRetencionEnabled || $salesPercepcionEnabled)
                ? $this->resolveSunatOperationTypes($salesDetraccionResolved, $salesRetencionResolved, $salesPercepcionResolved)
                : [],
            'commerce_features' => $commerceFeatures,
            'company_profile' => $this->resolveCompanyPrintProfileUseCase->execute($companyId),
        ];
    }

    public function listPriceTiers(int $companyId): Collection
    {
        return $this->referenceDocumentService->listPriceTiers($companyId);
    }

    public function resolveReferenceDocuments(Request $request): array
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));
        $isAdminUser = str_contains($roleCode, 'ADMIN');
        $isSellerUser = $roleProfile === 'SELLER'
            || str_contains($roleProfile, 'VENDED')
            || str_contains($roleCode, 'VENDED')
            || str_contains($roleCode, 'SELLER')
            || str_contains($roleCode, 'VENTA');
        $customerId = (int) $request->query('customer_id', 0);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $documentKindId = (int) $request->query('document_kind_id', 0);
        $noteKind = strtoupper(trim((string) $request->query('note_kind', '')));
        $limit = (int) $request->query('limit', 1000);

        if ($customerId <= 0) {
            return ['status' => 422, 'body' => ['message' => 'customer_id es requerido']];
        }

        if ($noteKind !== '' && !in_array($noteKind, ['CREDIT_NOTE', 'DEBIT_NOTE'], true)) {
            return ['status' => 422, 'body' => ['message' => 'note_kind invalido']];
        }

        $catalogRow = null;
        if ($documentKindId > 0) {
            $catalogRow = $this->documentKindCatalog()->firstWhere('id', $documentKindId);
        }

        $noteTargetKind = is_array($catalogRow) && !empty($catalogRow['note_target_kind'])
            ? (string) $catalogRow['note_target_kind']
            : null;

        try {
            $rows = $this->listReferenceDocuments(
                $companyId,
                $customerId,
                ($branchId !== null && $branchId !== '') ? (int) $branchId : null,
                $noteTargetKind,
                $noteKind,
                max(1, min(10000, max(1, $limit))),
                $isSellerUser ? (int) $authUser->id : ($isAdminUser ? null : (int) $authUser->id)
            );

            return ['status' => 200, 'body' => ['data' => $rows]];
        } catch (Throwable $e) {
            $fallbackRows = $this->resolveReferenceDocumentsFallback(
                $request,
                $companyId,
                $customerId,
                $noteTargetKind,
                $noteKind,
                max(1, min(10000, max(1, $limit)))
            );

            return ['status' => 200, 'body' => ['data' => $fallbackRows]];
        }
    }

    public function resolveSeriesNumbers(Request $request): array
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id');
        $documentKind = $request->query('document_kind');
        $documentKindId = (int) $request->query('document_kind_id', 0);
        $enabledOnly = filter_var($request->query('enabled_only', true), FILTER_VALIDATE_BOOLEAN);

        $branchIdFilter = ($branchId !== null && $branchId !== '') ? (int) $branchId : null;
        $warehouseIdFilter = ($warehouseId !== null && $warehouseId !== '') ? (int) $warehouseId : null;
        $resolvedDocumentKindId = null;
        $resolvedDocumentKindCode = null;

        if ($documentKindId > 0) {
            $resolvedDocumentKindId = $documentKindId;
        } elseif ($documentKind) {
            $resolvedDocumentKindCode = (string) $documentKind;
        }

        $rows = $this->listSeriesNumbers(
            $companyId,
            $branchIdFilter,
            $warehouseIdFilter,
            $enabledOnly,
            $resolvedDocumentKindId,
            $resolvedDocumentKindCode
        );

        return ['status' => 200, 'body' => ['data' => $rows]];
    }

    public function resolveTopProducts(Request $request): array
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $limit = max(1, min(12, (int) $request->query('limit', 4)));
        $days = max(7, min(365, (int) $request->query('days', 30)));

        return ['status' => 200, 'body' => ['data' => $this->listTopProducts($companyId, $limit, $days)]];
    }

    public function listReferenceDocuments(
        int $companyId,
        int $customerId,
        ?int $branchId,
        ?string $noteTargetKind,
        string $noteKind,
        int $limit,
        ?int $sellerUserId
    ): Collection {
        return $this->referenceDocumentService->listReferenceDocuments(
            $companyId,
            $customerId,
            $branchId,
            $noteTargetKind,
            $noteKind,
            $limit,
            $sellerUserId
        );
    }

    public function listSeriesNumbers(
        int $companyId,
        ?int $branchId,
        ?int $warehouseId,
        bool $enabledOnly,
        ?int $documentKindId,
        ?string $documentKindCode
    ): Collection {
        return $this->salesLookupService->listSeriesNumbers(
            $companyId,
            $branchId,
            $warehouseId,
            $enabledOnly,
            $documentKindId,
            $documentKindCode
        );
    }

    public function documentKindCatalog(): Collection
    {
        return $this->buildDocumentKindCatalog();
    }

    public function listTopProducts(int $companyId, int $limit, int $days): array
    {
        return $this->salesLookupService->listTopProducts($companyId, $limit, $days);
    }

    private function resolveReferenceDocumentsFallback(
        Request $request,
        int $companyId,
        int $customerId,
        ?string $noteTargetKind,
        string $noteKind,
        int $limit
    ): array {
        $filters = $this->buildCommercialDocumentFilters($request, $request->attributes->get('auth_user'));
        $filters['customer_id'] = $customerId;
        $filters['branch_id'] = $request->query('branch_id', $request->attributes->get('auth_user')->branch_id ?? null);

        $rows = $this->salesLookupService->listCommercialDocumentsForExport($companyId, $filters, max($limit * 4, $limit));

        return $rows
            ->filter(function ($row) use ($noteTargetKind): bool {
                $status = strtoupper(trim((string) ($row->status ?? '')));
                $sunatStatus = strtoupper(trim((string) ($row->sunat_status ?? '')));
                $documentKind = strtoupper(trim((string) ($row->document_kind ?? '')));

                if (in_array($status, ['VOID', 'CANCELED'], true)) {
                    return false;
                }

                if ($sunatStatus !== 'ACCEPTED') {
                    return false;
                }

                if ($noteTargetKind !== null) {
                    return $documentKind === strtoupper($noteTargetKind);
                }

                return in_array($documentKind, ['INVOICE', 'RECEIPT'], true);
            })
            ->map(function ($row) use ($customerId): array {
                return [
                    'id' => (int) ($row->id ?? 0),
                    'customer_id' => $customerId,
                    'document_kind' => strtoupper(trim((string) ($row->document_kind ?? ''))) === 'RECEIPT' ? 'RECEIPT' : 'INVOICE',
                    'series' => (string) ($row->series ?? ''),
                    'number' => (int) ($row->number ?? 0),
                    'issue_at' => (string) ($row->issue_at ?? ''),
                    'total' => (string) ($row->total ?? '0'),
                    'balance_due' => (string) ($row->balance_due ?? '0'),
                    'status' => (string) ($row->status ?? ''),
                    'applied_credit_total' => 0,
                    'applied_debit_total' => 0,
                    'has_credit_note' => false,
                    'has_debit_note' => false,
                ];
            })
            ->sort(function (array $left, array $right): int {
                $leftDate = strtotime($left['issue_at']) ?: 0;
                $rightDate = strtotime($right['issue_at']) ?: 0;

                if ($leftDate !== $rightDate) {
                    return $rightDate <=> $leftDate;
                }

                return $right['id'] <=> $left['id'];
            })
            ->take($limit)
            ->values()
            ->all();
    }

    private function resolveFeatureFromCollections(Collection $companyToggles, Collection $branchToggles, string $featureCode, bool $defaultEnabled): array
    {
        $normalizedCode = strtoupper(trim($featureCode));
        $branchRow = $branchToggles->firstWhere('feature_code', $normalizedCode);
        $companyRow = $companyToggles->firstWhere('feature_code', $normalizedCode);

        $branchEnabled = $branchRow && $branchRow->is_enabled !== null ? (bool) $branchRow->is_enabled : null;
        $companyEnabled = $companyRow && $companyRow->is_enabled !== null ? (bool) $companyRow->is_enabled : null;
        $resolvedEnabled = $branchEnabled !== null ? $branchEnabled : ($companyEnabled !== null ? $companyEnabled : $defaultEnabled);

        return [
            'is_enabled' => (bool) $resolvedEnabled,
            'company_enabled' => $companyEnabled,
            'branch_enabled' => $branchEnabled,
            'config' => array_merge(
                $this->decodeFeatureConfig($companyRow->config ?? null),
                $this->decodeFeatureConfig($branchRow->config ?? null)
            ),
        ];
    }

    private function decodeFeatureConfig($rawConfig): array
    {
        if ($rawConfig === null) {
            return [];
        }

        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($rawConfig) ? $rawConfig : [];
    }

    private function normalizeInventorySettings($row): array
    {
        if (!$row) {
            return [
                'complexity_mode' => 'BASIC',
                'inventory_mode' => 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => 'MANUAL',
                'enable_inventory_pro' => false,
                'enable_lot_tracking' => false,
                'enable_expiry_tracking' => false,
                'enable_advanced_reporting' => false,
                'enable_graphical_dashboard' => false,
                'enable_location_control' => false,
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => false,
                'low_stock_alert_threshold' => 5,
            ];
        }

        return [
            'complexity_mode' => (string) ($row->complexity_mode ?? 'BASIC'),
            'inventory_mode' => (string) ($row->inventory_mode ?? 'KARDEX_SIMPLE'),
            'lot_outflow_strategy' => (string) ($row->lot_outflow_strategy ?? 'MANUAL'),
            'enable_inventory_pro' => (bool) ($row->enable_inventory_pro ?? false),
            'enable_lot_tracking' => (bool) ($row->enable_lot_tracking ?? false),
            'enable_expiry_tracking' => (bool) ($row->enable_expiry_tracking ?? false),
            'enable_advanced_reporting' => (bool) ($row->enable_advanced_reporting ?? false),
            'enable_graphical_dashboard' => (bool) ($row->enable_graphical_dashboard ?? false),
            'enable_location_control' => (bool) ($row->enable_location_control ?? false),
            'allow_negative_stock' => (bool) ($row->allow_negative_stock ?? false),
            'enforce_lot_for_tracked' => (bool) ($row->enforce_lot_for_tracked ?? false),
            'low_stock_alert_threshold' => (int) ($row->low_stock_alert_threshold ?? 5),
        ];
    }

    private function resolveFeatureMinAmount(array $featureResolution, float $fallback): float
    {
        $config = is_array($featureResolution['config'] ?? null) ? $featureResolution['config'] : [];

        if (isset($config['min_amount']) && is_numeric($config['min_amount'])) {
            return (float) $config['min_amount'];
        }

        return $fallback;
    }

    private function resolveRetencionTypes(array $featureResolution): array
    {
        $defaultRate = 3.00;
        $defaultType = ['code' => 'RET_IGV_3', 'name' => 'Retencion IGV', 'rate_percent' => $defaultRate];
        $config = is_array($featureResolution['config'] ?? null) ? $featureResolution['config'] : [];
        $configuredTypes = isset($config['retencion_types']) && is_array($config['retencion_types']) ? $config['retencion_types'] : [];

        $rows = collect($configuredTypes)->map(function ($item) use ($defaultRate) {
            if (!is_array($item)) {
                return null;
            }

            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));
            $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent']) ? (float) $item['rate_percent'] : $defaultRate;

            return ['code' => $code, 'name' => $name, 'rate_percent' => $rate];
        })->filter(fn ($row) => is_array($row) && $row['code'] !== '' && $row['name'] !== '')->values()->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePercepcionTypes(array $featureResolution): array
    {
        $defaultRate = 2.00;
        $defaultType = ['code' => 'PERC_IGV_2', 'name' => 'Percepcion IGV', 'rate_percent' => $defaultRate];
        $config = is_array($featureResolution['config'] ?? null) ? $featureResolution['config'] : [];
        $configuredTypes = isset($config['percepcion_types']) && is_array($config['percepcion_types']) ? $config['percepcion_types'] : [];

        $rows = collect($configuredTypes)->map(function ($item) use ($defaultRate) {
            if (!is_array($item)) {
                return null;
            }

            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));
            $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent']) ? (float) $item['rate_percent'] : $defaultRate;

            return ['code' => $code, 'name' => $name, 'rate_percent' => $rate];
        })->filter(fn ($row) => is_array($row) && $row['code'] !== '' && $row['name'] !== '')->values()->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolveSunatOperationTypes(array $detraccionResolution, array $retencionResolution, array $percepcionResolution): array
    {
        $defaultRows = [
            ['code' => '0101', 'name' => 'Venta interna', 'regime' => 'NONE'],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION'],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION'],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION'],
        ];

        $configs = [$detraccionResolution['config'] ?? [], $retencionResolution['config'] ?? [], $percepcionResolution['config'] ?? []];
        $configuredRows = [];

        foreach ($configs as $config) {
            if (is_array($config) && isset($config['sunat_operation_types']) && is_array($config['sunat_operation_types'])) {
                $configuredRows = array_merge($configuredRows, $config['sunat_operation_types']);
            }
        }

        $rows = collect($configuredRows)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $regime = strtoupper(trim((string) ($item['regime'] ?? 'NONE')));
                if (!in_array($regime, ['NONE', 'DETRACCION', 'RETENCION', 'PERCEPCION'], true)) {
                    $regime = 'NONE';
                }

                return ['code' => $code, 'name' => $name, 'regime' => $regime];
            })
            ->filter(fn ($row) => is_array($row) && $row['code'] !== '' && $row['name'] !== '')
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : $defaultRows;
    }

    private function resolveFeatureAccountInfo(int $companyId, array $featureResolution, string $fallbackKeyword): ?array
    {
        $config = is_array($featureResolution['config'] ?? null) ? $featureResolution['config'] : [];
        $accountNumber = trim((string) ($config['account_number'] ?? ''));

        if ($accountNumber !== '') {
            return [
                'bank_name' => trim((string) ($config['bank_name'] ?? '')),
                'account_number' => $accountNumber,
                'account_holder' => trim((string) ($config['account_holder'] ?? '')),
            ];
        }

        $companyProfile = $this->resolveCompanyPrintProfileUseCase->execute($companyId);
        $bankAccounts = is_array($companyProfile['bank_accounts'] ?? null)
            ? array_values(array_filter($companyProfile['bank_accounts'], fn ($item) => is_array($item)))
            : [];

        $keyword = strtoupper(trim($fallbackKeyword));
        foreach ($bankAccounts as $account) {
            $accountType = strtoupper(trim((string) ($account['account_type'] ?? '')));
            $number = trim((string) ($account['account_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            if ($keyword !== '' && strpos($accountType, $keyword) === false) {
                continue;
            }

            return [
                'bank_name' => trim((string) ($account['bank_name'] ?? '')),
                'account_number' => $number,
                'account_holder' => trim((string) ($account['account_holder'] ?? '')),
            ];
        }

        return null;
    }

    private function buildCommercialDocumentFilters(Request $request, object $authUser): array
    {
        return $request->query();
    }

    private function buildDocumentKindCatalog(): Collection
    {
        return collect($this->salesLookupService->listDocumentKindsCatalog())
            ->map(function (array $row) {
                $code = (string) data_get($row, 'code', '');
                $label = (string) data_get($row, 'label', '');
                $meta = $this->documentKindMeta($code, $label);
                return [
                    'id' => (int) data_get($row, 'id', 0),
                    'code' => $code,
                    'label' => $label,
                    'is_enabled' => (bool) data_get($row, 'is_enabled', true),
                    'base_kind' => $meta['base_kind'],
                    'kind_group' => $meta['kind_group'],
                    'note_target_kind' => $meta['note_target_kind'],
                ];
            })
            ->values();
    }

    private function documentKindMeta(string $code, string $label = ''): array
    {
        $normalizedCode = strtoupper(trim($code));
        $normalizedLabel = strtoupper(trim($label));
        $baseKind = $normalizedCode;
        $kindGroup = 'TRIBUTARY';
        $noteTargetKind = null;

        if ($normalizedCode === 'QUOTATION' || $normalizedCode === 'SALES_ORDER') {
            $kindGroup = 'PRE_DOCUMENT';
        }

        if ($normalizedCode === 'CREDIT_NOTE' || strpos($normalizedCode, 'CREDIT_NOTE_') === 0) {
            $baseKind = 'CREDIT_NOTE';
            $kindGroup = 'NOTE_CREDIT';
        }

        if ($normalizedCode === 'DEBIT_NOTE' || strpos($normalizedCode, 'DEBIT_NOTE_') === 0) {
            $baseKind = 'DEBIT_NOTE';
            $kindGroup = 'NOTE_DEBIT';
        }

        if ($kindGroup === 'NOTE_CREDIT' || $kindGroup === 'NOTE_DEBIT') {
            $targetHint = $normalizedCode . ' ' . $normalizedLabel;
            if (strpos($targetHint, 'RECEIPT') !== false || strpos($targetHint, 'BOLETA') !== false) {
                $noteTargetKind = 'RECEIPT';
            } elseif (strpos($targetHint, 'INVOICE') !== false || strpos($targetHint, 'FACTURA') !== false) {
                $noteTargetKind = 'INVOICE';
            }
        }

        return [
            'base_kind' => $baseKind,
            'kind_group' => $kindGroup,
            'note_target_kind' => $noteTargetKind,
        ];
    }

    private function findDocumentKindCatalogRowById(int $id): ?array
    {
        return $this->buildDocumentKindCatalog()->first(fn ($row) => (int) ($row['id'] ?? 0) === $id);
    }

    private function findDocumentKindCatalogRowByCode(string $code): ?array
    {
        $normalizedCode = strtoupper(trim($code));
        return $this->buildDocumentKindCatalog()->first(fn ($row) => strtoupper(trim((string) ($row['code'] ?? ''))) === $normalizedCode);
    }

}
