<?php

namespace App\Http\Controllers\Api;

use App\Application\Commands\Purchases\ExportPurchasesStockEntriesCommand;
use App\Application\Commands\Purchases\ListPurchasesStockEntriesCommand;
use App\Application\UseCases\Purchases\ExportPurchasesStockEntriesUseCase;
use App\Application\UseCases\Purchases\GetPurchasesLookupsUseCase;
use App\Application\UseCases\Purchases\ListPurchasesStockEntriesUseCase;
use App\Services\AppConfig\CommerceFeatureToggleService;
use App\Services\AppConfig\CompanyIgvRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasesController
{
    public function __construct(
        private CommerceFeatureToggleService $featureToggles,
        private CompanyIgvRateService $companyIgvRateService,
        private GetPurchasesLookupsUseCase $getPurchasesLookupsUseCase,
        private ListPurchasesStockEntriesUseCase $listPurchasesStockEntriesUseCase,
        private ExportPurchasesStockEntriesUseCase $exportPurchasesStockEntriesUseCase
    )
    {
    }

    /**
     * Get lookups for purchases (payment methods, etc.)
     */
    public function lookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        $baseLookups = $this->getPurchasesLookupsUseCase->execute($companyId);

        $detraccionEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_DETRACCION_ENABLED');
        $retencionCompradorEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_RETENCION_COMPRADOR_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_RETENCION_COMPRADOR_ENABLED');
        $retencionProveedorEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_RETENCION_PROVEEDOR_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_RETENCION_PROVEEDOR_ENABLED');
        $percepcionEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_PERCEPCION_ENABLED');

        $retencionFeatureCode = $retencionCompradorEnabled
            ? 'PURCHASES_RETENCION_COMPRADOR_ENABLED'
            : ($retencionProveedorEnabled ? 'PURCHASES_RETENCION_PROVEEDOR_ENABLED' : null);

        return response()->json([
            'payment_methods' => $baseLookups['payment_methods'],
            'tax_categories' => $baseLookups['tax_categories'],
            'active_igv_rate_percent' => $this->companyIgvRateService->resolveActiveRatePercent($companyId),
            'inventory_settings' => $baseLookups['inventory_settings'],
            'detraccion_service_codes' => $detraccionEnabled ? $this->resolveDetractionServiceCodes() : [],
            'detraccion_min_amount' => $detraccionEnabled ? $this->getDetractionMinAmount($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED') : null,
            'detraccion_account' => $detraccionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED', 'DETRACCION') : null,
            'retencion_comprador_enabled' => $retencionCompradorEnabled,
            'retencion_proveedor_enabled' => $retencionProveedorEnabled,
            'retencion_types' => ($retencionCompradorEnabled || $retencionProveedorEnabled)
                ? $this->resolveRetencionTypes($companyId, $branchId, $retencionCompradorEnabled, $retencionProveedorEnabled)
                : [],
            'retencion_account' => $retencionFeatureCode
                ? $this->resolveFeatureAccountInfo($companyId, $branchId, $retencionFeatureCode, 'RETENCION')
                : null,
            'retencion_percentage' => 3.00,
            'percepcion_enabled' => $percepcionEnabled,
            'percepcion_types' => $percepcionEnabled ? $this->resolvePercepcionTypes($companyId, $branchId) : [],
            'percepcion_account' => $percepcionEnabled
                ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED', 'PERCEPCION')
                : null,
            'sunat_operation_types' => ($detraccionEnabled || $retencionCompradorEnabled || $retencionProveedorEnabled || $percepcionEnabled)
                ? $this->resolveSunatOperationTypes($companyId, $branchId)
                : [],
        ]);
    }

    /**
     * List stock entries (purchases and adjustments) with filtering and pagination
     */
    public function listStockEntries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id');

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        // Filtering parameters
        $entryType = $request->query('entry_type'); // PURCHASE, ADJUSTMENT, or null for both
        $reference = $request->query('reference');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $warehouseId = $request->query('warehouse_id');

        // Pagination
        $perPage = min((int) $request->query('per_page', 10), 100);
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $result = $this->listPurchasesStockEntriesUseCase->execute(
            ListPurchasesStockEntriesCommand::fromInput(
                $companyId,
                $branchId,
                $entryType,
                $reference,
                $dateFrom,
                $dateTo,
                $warehouseId,
                $page,
                $perPage
            )
        );

        return response()->json([
            'data' => $result['data'],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * Export stock entries as CSV
     */
    public function exportStockEntries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id');

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        // Filtering parameters (same as listStockEntries)
        $entryType = $request->query('entry_type');
        $reference = $request->query('reference');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $warehouseId = $request->query('warehouse_id');
        $format = strtolower($request->query('format', 'csv')); // csv or xlsx

        $entries = $this->exportPurchasesStockEntriesUseCase->execute(
            ExportPurchasesStockEntriesCommand::fromInput(
                $companyId,
                $branchId,
                $entryType,
                $reference,
                $dateFrom,
                $dateTo,
                $warehouseId,
                $format === 'json'
            )
        );

        if ($format === 'json') {
            return response()->json([
                'data' => $entries,
            ]);
        }

        if ($format === 'xlsx') {
            return $this->exportAsExcel($entries);
        } else {
            return $this->exportAsCsv($entries);
        }
    }

    /**
     * Attach detail lines for each stock entry.
     */
    private function attachEntryItems($entries, int $companyId)
    {
        $entryIds = collect($entries)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->filter(function ($id) {
            return $id > 0;
        })->values();

        if ($entryIds->isEmpty()) {
            return $entries;
        }

        $itemColumns = $this->tableColumns('inventory.stock_entry_items');
        $hasTaxCategory = in_array('tax_category_id', $itemColumns, true);
        $hasTaxRate = in_array('tax_rate', $itemColumns, true);

        $taxById = $this->resolveTaxCategories($companyId)->keyBy('id');

        $items = DB::table('inventory.stock_entry_items as sei')
            ->leftJoin('inventory.products as p', 'sei.product_id', '=', 'p.id')
            ->leftJoin('inventory.product_lots as pl', 'sei.lot_id', '=', 'pl.id')
            ->whereIn('sei.entry_id', $entryIds->all())
            ->select([
                'sei.entry_id',
                'sei.product_id',
                DB::raw('COALESCE(p.name, CONCAT(\'Producto #\', sei.product_id)) as product_name'),
                'sei.qty',
                'sei.unit_cost',
                $hasTaxCategory ? 'sei.tax_category_id' : DB::raw('NULL as tax_category_id'),
                $hasTaxRate ? 'sei.tax_rate' : DB::raw('0 as tax_rate'),
                'sei.notes',
                'pl.lot_code',
            ])
            ->orderBy('sei.entry_id')
            ->orderBy('p.name')
            ->orderBy('sei.id')
            ->get()
            ->map(function ($row) use ($taxById) {
                $subtotal = (float) $row->qty * (float) $row->unit_cost;
                $taxRate = (float) ($row->tax_rate ?? 0);
                $taxAmount = $subtotal * ($taxRate / 100);
                $taxCategoryId = $row->tax_category_id ? (int) $row->tax_category_id : null;
                $taxRow = $taxCategoryId ? $taxById->get($taxCategoryId) : null;

                return [
                    'entry_id' => (int) $row->entry_id,
                    'product_id' => (int) $row->product_id,
                    'product_name' => (string) $row->product_name,
                    'qty' => (float) $row->qty,
                    'unit_cost' => (float) $row->unit_cost,
                    'subtotal' => round($subtotal, 4),
                    'tax_category_id' => $taxCategoryId,
                    'tax_label' => $taxRow['label'] ?? 'Sin IGV',
                    'tax_rate' => $taxRate,
                    'tax_amount' => round($taxAmount, 4),
                    'line_total' => round($subtotal + $taxAmount, 4),
                    'lot_code' => $row->lot_code,
                    'notes' => $row->notes,
                ];
            })
            ->groupBy('entry_id');

        return collect($entries)->map(function ($entry) use ($items) {
            $row = (array) $entry;
            $row['items'] = $items->get((int) $entry->id, collect())->values()->all();
            return $row;
        })->values()->all();
    }

    /**
     * Export entries as CSV
     */
    private function exportAsCsv($entries)
    {
        $csv = "ID,Tipo,Referencia,Referencia_Proveedor,Fecha,Almacen,Cantidad_Items,Cantidad_Total,Importe_Total,Metodo_Pago,Notas\n";

        foreach ($entries as $entry) {
            $row = [
                $entry->id,
                $entry->entry_type === 'PURCHASE' ? 'Compra' : 'Ajuste',
                '"' . str_replace('"', '""', $entry->reference_no ?? '') . '"',
                '"' . str_replace('"', '""', $entry->supplier_reference ?? '') . '"',
                substr($entry->issue_at, 0, 10),
                '"' . str_replace('"', '""', $entry->warehouse_name ?? $entry->warehouse_code ?? '') . '"',
                $entry->total_items,
                number_format($entry->total_qty, 3, '.', ''),
                number_format($entry->total_amount, 2, '.', ''),
                '"' . str_replace('"', '""', $entry->payment_method ?? '') . '"',
                '"' . str_replace('"', '""', $entry->notes ?? '') . '"',
            ];
            $csv .= implode(',', $row) . "\n";
        }

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="reporte_compras_' . date('Ymd_His') . '.csv"');
    }

    /**
     * Export entries as Excel (basic: using CSV format for now, can integrate PhpSpreadsheet later)
     */
    private function exportAsExcel($entries)
    {
        // For now, return the same CSV but signal it as Excel
        // Full Excel support requires package: composer require maatwebsite/excel
        return $this->exportAsCsv($entries)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="reporte_compras_' . date('Ymd_His') . '.xlsx"');
    }

    private function resolveTaxCategories(int $companyId)
    {
        $sourceTable = null;

        foreach (['core.tax_categories', 'sales.tax_categories', 'appcfg.tax_categories'] as $candidate) {
            if ($this->tableExists($candidate)) {
                $sourceTable = $candidate;
                break;
            }
        }

        if (!$sourceTable) {
            return collect();
        }

        $columns = $this->tableColumns($sourceTable);
        $idColumn = $this->firstExistingColumn($columns, ['id']);
        $codeColumn = $this->firstExistingColumn($columns, ['code', 'sunat_code', 'tax_code']);
        $labelColumn = $this->firstExistingColumn($columns, ['name', 'label', 'description']);
        $rateColumn = $this->firstExistingColumn($columns, ['rate_percent', 'rate', 'percentage', 'tax_rate']);
        $statusColumn = $this->firstExistingColumn($columns, ['status', 'is_enabled', 'enabled', 'active']);
        $companyColumn = $this->firstExistingColumn($columns, ['company_id']);

        $query = DB::table($sourceTable);

        if ($statusColumn) {
            if ($statusColumn === 'status') {
                $query->where($statusColumn, 1);
            } else {
                $query->where($statusColumn, true);
            }
        }

        if ($companyColumn) {
            $query->where(function ($nested) use ($companyColumn, $companyId) {
                $nested->where($companyColumn, $companyId)
                    ->orWhereNull($companyColumn);
            });
        }

        return $query->get()->map(function ($row) use ($idColumn, $codeColumn, $labelColumn, $rateColumn) {
            $id = $idColumn ? (int) ($row->{$idColumn} ?? 0) : 0;
            $code = $codeColumn ? (string) ($row->{$codeColumn} ?? '') : '';
            $label = $labelColumn ? (string) ($row->{$labelColumn} ?? '') : '';
            $rate = $rateColumn ? (float) ($row->{$rateColumn} ?? 0) : 0.0;

            if ($label === '') {
                $label = $code !== '' ? $code : ('IGV #' . $id);
            }

            return [
                'id' => $id,
                'code' => $code,
                'label' => $label,
                'rate_percent' => round($rate, 4),
            ];
        })->filter(function ($row) {
            return $row['id'] > 0;
        })->values();
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

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
        ];
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        return isset($row->present) && (bool) $row->present;
    }

    private function tableColumns(string $qualifiedTable)
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $rows = DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [$schema, $table]
        );

        return collect($rows)->map(function ($row) {
            return (string) $row->column_name;
        })->values()->all();
    }

    private function firstExistingColumn(array $columns, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') !== false) {
            return explode('.', $qualifiedTable, 2);
        }

        return ['public', $qualifiedTable];
    }

    private function isFeatureEnabled(int $companyId, $branchId, string $featureCode): bool
    {
        return $this->featureToggles->isFeatureEnabledForContext($companyId, $branchId, $featureCode);
    }

    private function isCommerceFeatureEnabled(int $companyId, string $featureCode): bool
    {
        return $this->featureToggles->isCompanyFeatureEnabled($companyId, $featureCode);
    }

    private function getDetractionMinAmount(int $companyId, $branchId, string $featureCode): float
    {
        $row = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        if ($row) {
            $config = $this->decodeFeatureConfig($row->config ?? null);
            if (isset($config['min_amount']) && is_numeric($config['min_amount'])) {
                return (float) $config['min_amount'];
            }
        }

        return 700.00;
    }

    private function resolveRetencionTypes(int $companyId, $branchId, bool $retencionCompradorEnabled, bool $retencionProveedorEnabled): array
    {
        $defaultRate = 3.00;
        $defaultType = [
            'code' => 'RET_IGV_3',
            'name' => 'Retencion IGV',
            'rate_percent' => $defaultRate,
        ];

        $configuredTypes = [];
        if ($retencionCompradorEnabled) {
            $row = $this->resolveFeatureToggleRow($companyId, $branchId, 'PURCHASES_RETENCION_COMPRADOR_ENABLED');
            $config = $this->decodeFeatureConfig($row ? $row->config : null);
            if (isset($config['retencion_types']) && is_array($config['retencion_types'])) {
                $configuredTypes = array_merge($configuredTypes, $config['retencion_types']);
            }
        }
        if ($retencionProveedorEnabled) {
            $row = $this->resolveFeatureToggleRow($companyId, $branchId, 'PURCHASES_RETENCION_PROVEEDOR_ENABLED');
            $config = $this->decodeFeatureConfig($row ? $row->config : null);
            if (isset($config['retencion_types']) && is_array($config['retencion_types'])) {
                $configuredTypes = array_merge($configuredTypes, $config['retencion_types']);
            }
        }

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePercepcionTypes(int $companyId, $branchId): array
    {
        $defaultRate = 2.00;
        $defaultType = [
            'code' => 'PERC_IGV_2',
            'name' => 'Percepcion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
        $configuredTypes = isset($config['percepcion_types']) && is_array($config['percepcion_types'])
            ? $config['percepcion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolveSunatOperationTypes(int $companyId, $branchId): array
    {
        $defaultRows = [
            ['code' => '0101', 'name' => 'Compra interna', 'regime' => 'NONE'],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION'],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION'],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION'],
        ];

        $featureCodes = [
            'PURCHASES_DETRACCION_ENABLED',
            'PURCHASES_RETENCION_COMPRADOR_ENABLED',
            'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
            'PURCHASES_PERCEPCION_ENABLED',
        ];

        $configuredRows = [];
        foreach ($featureCodes as $featureCode) {
            $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
            $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
            if (isset($config['sunat_operation_types']) && is_array($config['sunat_operation_types'])) {
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

                return [
                    'code' => $code,
                    'name' => $name,
                    'regime' => $regime,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : $defaultRows;
    }

    private function resolveFeatureAccountInfo(int $companyId, $branchId, string $featureCode, string $fallbackKeyword): ?array
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $accountNumber = trim((string) ($config['account_number'] ?? ''));
        if ($accountNumber !== '') {
            return [
                'bank_name' => trim((string) ($config['bank_name'] ?? '')),
                'account_number' => $accountNumber,
                'account_holder' => trim((string) ($config['account_holder'] ?? '')),
            ];
        }

        $bankAccounts = $this->resolveCompanyBankAccounts($companyId);
        $keyword = strtoupper(trim($fallbackKeyword));
        foreach ($bankAccounts as $account) {
            if (!is_array($account)) {
                continue;
            }

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

    private function resolveCompanyBankAccounts(int $companyId): array
    {
        if (!$this->tableExists('core.company_settings')) {
            return [];
        }

        $row = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('bank_accounts')
            ->first();

        if (!$row || $row->bank_accounts === null) {
            return [];
        }

        $decoded = is_string($row->bank_accounts)
            ? json_decode($row->bank_accounts, true)
            : (array) $row->bank_accounts;

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function ($item) {
            return is_array($item);
        }));
    }

    private function resolveFeatureToggleRow(int $companyId, $branchId, string $featureCode)
    {
        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->first();

            if ($branchRow && (bool) ($branchRow->is_enabled ?? false)) {
                return $branchRow;
            }

            if ($companyRow && (bool) ($companyRow->is_enabled ?? false)) {
                return $companyRow;
            }

            if ($branchRow) {
                return $branchRow;
            }
        }

        return $companyRow;
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

        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        return [];
    }

    private function resolveDetractionServiceCodes(): array
    {
        if (!$this->tableExists('master.detraccion_service_codes')) {
            return [];
        }

        return DB::table('master.detraccion_service_codes')
            ->select('id', 'code', 'name', 'rate_percent')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get()
            ->map(function ($row) {
                return [
                    'id'           => (int) $row->id,
                    'code'         => (string) $row->code,
                    'name'         => (string) $row->name,
                    'rate_percent' => (float) $row->rate_percent,
                ];
            })
            ->values()
            ->all();
    }
}
