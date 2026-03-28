<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasesController
{
    /**
     * Get lookups for purchases (payment methods, etc.)
     */
    public function lookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $paymentMethods = DB::table('core.payment_methods')
            ->select('id', 'code', 'name')
            ->where('status', 1)
            ->orderBy('name')
            ->get();

        return response()->json([
            'payment_methods' => $paymentMethods,
            'tax_categories' => $this->resolveTaxCategories($companyId),
            'inventory_settings' => $this->inventorySettingsForCompany($companyId),
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

        $stockEntryColumns = $this->tableColumns('inventory.stock_entries');
        $hasPaymentMethodId = in_array('payment_method_id', $stockEntryColumns, true);

        $summarySubquery = DB::table('inventory.stock_entry_items as sei')
            ->selectRaw('sei.entry_id, COUNT(*) as total_items, COALESCE(SUM(sei.qty), 0) as total_qty, COALESCE(SUM(sei.qty * sei.unit_cost), 0) as total_amount')
            ->groupBy('sei.entry_id');

        $query = DB::table('inventory.stock_entries as se')
            ->leftJoin('inventory.warehouses as w', 'se.warehouse_id', '=', 'w.id')
            ->leftJoinSub($summarySubquery, 's', function ($join) {
                $join->on('s.entry_id', '=', 'se.id');
            })
            ->select(
                'se.id',
                'se.entry_type',
                'se.reference_no',
                'se.supplier_reference',
                'se.issue_at',
                'se.notes',
                DB::raw('COALESCE(s.total_items, 0) as total_items'),
                DB::raw('COALESCE(s.total_qty, 0) as total_qty'),
                DB::raw('COALESCE(s.total_amount, 0) as total_amount'),
                'w.code as warehouse_code',
                'w.name as warehouse_name'
            )
            ->where('se.company_id', $companyId)
            ->where('se.status', 'APPLIED');

        if ($hasPaymentMethodId) {
            $query->leftJoin('core.payment_methods as pm', 'se.payment_method_id', '=', 'pm.id')
                ->addSelect(DB::raw('COALESCE(pm.name, \'No especificado\') as payment_method'));
        } else {
            $query->addSelect(DB::raw('\'No especificado\' as payment_method'));
        }

        // Apply optional filters
        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId) {
                $q->where('se.branch_id', (int) $branchId)
                    ->orWhereNull('se.branch_id');
            });
        }

        if ($entryType && in_array($entryType, ['PURCHASE', 'ADJUSTMENT'])) {
            $query->where('se.entry_type', $entryType);
        }

        if ($reference) {
            $searchTerm = '%' . $reference . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('se.reference_no', 'ilike', $searchTerm)
                    ->orWhere('se.supplier_reference', 'ilike', $searchTerm);
            });
        }

        if ($dateFrom) {
            $query->whereDate('se.issue_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('se.issue_at', '<=', $dateTo);
        }

        if ($warehouseId) {
            $query->where('se.warehouse_id', (int) $warehouseId);
        }

        // Count total records before pagination
        $total = $query->count();

        // Get paginated results
        $entries = $query->orderBy('se.issue_at', 'desc')
            ->orderBy('se.id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $entries = $this->attachEntryItems($entries, $companyId);

        return response()->json([
            'data' => $entries,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
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

        $stockEntryColumns = $this->tableColumns('inventory.stock_entries');
        $hasPaymentMethodId = in_array('payment_method_id', $stockEntryColumns, true);

        $summarySubquery = DB::table('inventory.stock_entry_items as sei')
            ->selectRaw('sei.entry_id, COUNT(*) as total_items, COALESCE(SUM(sei.qty), 0) as total_qty, COALESCE(SUM(sei.qty * sei.unit_cost), 0) as total_amount')
            ->groupBy('sei.entry_id');

        $query = DB::table('inventory.stock_entries as se')
            ->leftJoin('inventory.warehouses as w', 'se.warehouse_id', '=', 'w.id')
            ->leftJoinSub($summarySubquery, 's', function ($join) {
                $join->on('s.entry_id', '=', 'se.id');
            })
            ->select(
                'se.id',
                'se.entry_type',
                'se.reference_no',
                'se.supplier_reference',
                'se.issue_at',
                'se.notes',
                DB::raw('COALESCE(s.total_items, 0) as total_items'),
                DB::raw('COALESCE(s.total_qty, 0) as total_qty'),
                DB::raw('COALESCE(s.total_amount, 0) as total_amount'),
                'w.code as warehouse_code',
                'w.name as warehouse_name'
            )
            ->where('se.company_id', $companyId)
            ->where('se.status', 'APPLIED');

        if ($hasPaymentMethodId) {
            $query->leftJoin('core.payment_methods as pm', 'se.payment_method_id', '=', 'pm.id')
                ->addSelect(DB::raw('COALESCE(pm.name, \'No especificado\') as payment_method'));
        } else {
            $query->addSelect(DB::raw('\'No especificado\' as payment_method'));
        }

        // Apply same filters
        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId) {
                $q->where('se.branch_id', (int) $branchId)
                    ->orWhereNull('se.branch_id');
            });
        }

        if ($entryType && in_array($entryType, ['PURCHASE', 'ADJUSTMENT'])) {
            $query->where('se.entry_type', $entryType);
        }

        if ($reference) {
            $searchTerm = '%' . $reference . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('se.reference_no', 'ilike', $searchTerm)
                    ->orWhere('se.supplier_reference', 'ilike', $searchTerm);
            });
        }

        if ($dateFrom) {
            $query->whereDate('se.issue_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('se.issue_at', '<=', $dateTo);
        }

        if ($warehouseId) {
            $query->where('se.warehouse_id', (int) $warehouseId);
        }

        $entries = $query->orderBy('se.issue_at', 'desc')
            ->orderBy('se.id', 'desc')
            ->get();

        if ($format === 'json') {
            $entries = $this->attachEntryItems($entries, $companyId);

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
}
