<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateInventoryReportJob;
use App\Support\Inventory\ProjectionEngine;
use App\Support\Inventory\ReportEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryReportsController extends Controller
{
    private const BASIC_ALLOWED_REPORT_TYPES = [
        'STOCK_SNAPSHOT',
        'KARDEX_PHYSICAL',
        'KARDEX_VALUED',
        'INVENTORY_CUT',
    ];

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return [
                'enable_inventory_pro' => false,
                'enable_advanced_reporting' => false,
                'enable_graphical_dashboard' => false,
                'enable_expiry_tracking' => false,
            ];
        }

        return [
            'enable_inventory_pro' => (bool) ($row->enable_inventory_pro ?? false),
            'enable_advanced_reporting' => (bool) ($row->enable_advanced_reporting ?? false),
            'enable_graphical_dashboard' => (bool) ($row->enable_graphical_dashboard ?? false),
            'enable_expiry_tracking' => (bool) ($row->enable_expiry_tracking ?? false),
        ];
    }

    private function ensureInventoryProEnabled(int $companyId, bool $requireAdvancedReporting = false, bool $requireDashboard = false, bool $requireExpiry = false): void
    {
        $settings = $this->inventorySettingsForCompany($companyId);

        if (!(bool) $settings['enable_inventory_pro']) {
            throw new \RuntimeException('Inventory Pro is disabled for this company');
        }
        if ($requireAdvancedReporting && !(bool) $settings['enable_advanced_reporting']) {
            throw new \RuntimeException('Advanced reporting is disabled for this company');
        }
        if ($requireDashboard && !(bool) $settings['enable_graphical_dashboard']) {
            throw new \RuntimeException('Graphical dashboard is disabled for this company');
        }
        if ($requireExpiry && !(bool) $settings['enable_expiry_tracking']) {
            throw new \RuntimeException('Expiry tracking is disabled for this company');
        }
    }

    private function reportTypeAllowedForSettings(string $reportType, array $settings): bool
    {
        $normalized = strtoupper($reportType);
        $inventoryPro = (bool) ($settings['enable_inventory_pro'] ?? false);

        if ($inventoryPro) {
            if ($normalized === 'LOT_EXPIRY' && !(bool) ($settings['enable_expiry_tracking'] ?? false)) {
                return false;
            }

            return true;
        }

        return in_array($normalized, self::BASIC_ALLOWED_REPORT_TYPES, true);
    }

    public function dashboard(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $days = min(max((int) $request->query('days', 30), 1), 180);
        $warehouseId = $request->query('warehouse_id');

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $settings = $this->inventorySettingsForCompany($companyId);
        $inventoryPro = (bool) ($settings['enable_inventory_pro'] ?? false);
        $advancedReporting = $inventoryPro && (bool) ($settings['enable_advanced_reporting'] ?? false);
        $expiryEnabled = $inventoryPro && (bool) ($settings['enable_expiry_tracking'] ?? false);

        ProjectionEngine::ensureSchema();
        if ($expiryEnabled) {
            ProjectionEngine::refreshLotExpiryProjection($companyId, $warehouseId !== null && $warehouseId !== '' ? (int) $warehouseId : null);
        }

        $snapshotFrom = now()->subDays($days - 1)->toDateString();

        $stockSummaryQuery = DB::table('inventory.current_stock as cs')
            ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
            ->where('cs.company_id', $companyId);
        if ($warehouseId !== null && $warehouseId !== '') {
            $stockSummaryQuery->where('cs.warehouse_id', (int) $warehouseId);
        }

        $stockSummary = $stockSummaryQuery
            ->selectRaw('COUNT(*) as rows, COALESCE(SUM(cs.stock), 0) as total_qty, COALESCE(SUM(cs.stock * p.cost_price), 0) as total_value')
            ->first();

        $expiryBuckets = collect();
        if ($expiryEnabled) {
            $expiryQuery = DB::table('inventory.lot_expiry_projection')
                ->where('company_id', $companyId);
            if ($warehouseId !== null && $warehouseId !== '') {
                $expiryQuery->where('warehouse_id', (int) $warehouseId);
            }

            $expiryBuckets = $expiryQuery
                ->selectRaw('COALESCE(expiry_bucket, \'NO_EXPIRY\') as bucket, COUNT(*) as total_lots, COALESCE(SUM(stock), 0) as total_stock, COALESCE(SUM(stock_value), 0) as total_value')
                ->groupBy('bucket')
                ->get();
        }

        if ($advancedReporting) {
            $movementTrendQuery = DB::table('inventory.stock_daily_snapshot as ds')
                ->where('ds.company_id', $companyId)
                ->where('ds.snapshot_date', '>=', $snapshotFrom);
            if ($warehouseId !== null && $warehouseId !== '') {
                $movementTrendQuery->where('ds.warehouse_id', (int) $warehouseId);
            }

            $movementTrend = $movementTrendQuery
                ->selectRaw('snapshot_date, COALESCE(SUM(qty_in), 0) as qty_in, COALESCE(SUM(qty_out), 0) as qty_out, COALESCE(SUM(value_in), 0) as value_in, COALESCE(SUM(value_out), 0) as value_out')
                ->groupBy('snapshot_date')
                ->orderBy('snapshot_date')
                ->get();

            $topProductsQuery = DB::table('inventory.stock_daily_snapshot as ds')
                ->join('inventory.products as p', 'p.id', '=', 'ds.product_id')
                ->where('ds.company_id', $companyId)
                ->where('ds.snapshot_date', '>=', $snapshotFrom);
            if ($warehouseId !== null && $warehouseId !== '') {
                $topProductsQuery->where('ds.warehouse_id', (int) $warehouseId);
            }

            $topProducts = $topProductsQuery
                ->selectRaw('ds.product_id, p.sku as product_sku, p.name as product_name, COALESCE(SUM(ds.qty_in), 0) as qty_in, COALESCE(SUM(ds.qty_out), 0) as qty_out, COALESCE(SUM(ABS(ds.value_net)), 0) as movement_value')
                ->groupBy('ds.product_id', 'p.sku', 'p.name')
                ->orderByDesc('movement_value')
                ->limit(10)
                ->get();
        } else {
            $movementTrendQuery = DB::table('inventory.inventory_ledger as il')
                ->where('il.company_id', $companyId)
                ->whereDate('il.moved_at', '>=', $snapshotFrom);
            if ($warehouseId !== null && $warehouseId !== '') {
                $movementTrendQuery->where('il.warehouse_id', (int) $warehouseId);
            }

            $movementTrend = $movementTrendQuery
                ->selectRaw('DATE(il.moved_at) as snapshot_date, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity ELSE 0 END), 0) as qty_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity ELSE 0 END), 0) as qty_out, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity * il.unit_cost ELSE 0 END), 0) as value_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity * il.unit_cost ELSE 0 END), 0) as value_out')
                ->groupByRaw('DATE(il.moved_at)')
                ->orderBy('snapshot_date')
                ->get();

            $topProductsQuery = DB::table('inventory.inventory_ledger as il')
                ->join('inventory.products as p', 'p.id', '=', 'il.product_id')
                ->where('il.company_id', $companyId)
                ->whereDate('il.moved_at', '>=', $snapshotFrom);
            if ($warehouseId !== null && $warehouseId !== '') {
                $topProductsQuery->where('il.warehouse_id', (int) $warehouseId);
            }

            $topProducts = $topProductsQuery
                ->selectRaw('il.product_id, p.sku as product_sku, p.name as product_name, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity ELSE 0 END), 0) as qty_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity ELSE 0 END), 0) as qty_out, COALESCE(SUM(ABS(il.quantity * il.unit_cost)), 0) as movement_value')
                ->groupBy('il.product_id', 'p.sku', 'p.name')
                ->orderByDesc('movement_value')
                ->limit(10)
                ->get();
        }

        return response()->json([
            'profile' => [
                'inventory_pro' => $inventoryPro,
                'advanced_reporting' => $advancedReporting,
                'graphical_dashboard' => $inventoryPro && (bool) ($settings['enable_graphical_dashboard'] ?? false),
                'expiry_tracking' => $expiryEnabled,
            ],
            'summary' => [
                'days' => $days,
                'stock_rows' => (int) ($stockSummary->rows ?? 0),
                'total_qty' => (float) ($stockSummary->total_qty ?? 0),
                'total_value' => (float) ($stockSummary->total_value ?? 0),
            ],
            'expiry_buckets' => $expiryBuckets,
            'movement_trend' => $movementTrend,
            'top_products' => $topProducts,
        ]);
    }

    public function dailySnapshot(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $dateFrom = (string) $request->query('date_from', now()->subDays(7)->toDateString());
        $dateTo = (string) $request->query('date_to', now()->toDateString());
        $warehouseId = $request->query('warehouse_id');
        $productId = $request->query('product_id');
        $limit = min(max((int) $request->query('limit', 500), 1), 5000);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $settings = $this->inventorySettingsForCompany($companyId);
        $advancedReporting = (bool) ($settings['enable_inventory_pro'] ?? false) && (bool) ($settings['enable_advanced_reporting'] ?? false);

        if ($advancedReporting) {
            ProjectionEngine::ensureSchema();
            $query = DB::table('inventory.stock_daily_snapshot as ds')
                ->leftJoin('inventory.products as p', 'p.id', '=', 'ds.product_id')
                ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'ds.warehouse_id')
                ->leftJoin('inventory.product_lots as pl', 'pl.id', '=', 'ds.lot_id')
                ->select([
                    'ds.snapshot_date',
                    'ds.warehouse_id',
                    DB::raw('w.code as warehouse_code'),
                    DB::raw('w.name as warehouse_name'),
                    'ds.product_id',
                    DB::raw('p.sku as product_sku'),
                    DB::raw('p.name as product_name'),
                    'ds.lot_id',
                    DB::raw('pl.lot_code as lot_code'),
                    'ds.qty_in',
                    'ds.qty_out',
                    'ds.qty_net',
                    'ds.value_in',
                    'ds.value_out',
                    'ds.value_net',
                    'ds.movement_count',
                    'ds.first_moved_at',
                    'ds.last_moved_at',
                ])
                ->where('ds.company_id', $companyId)
                ->whereBetween('ds.snapshot_date', [$dateFrom, $dateTo])
                ->orderByDesc('ds.snapshot_date')
                ->orderBy('p.name')
                ->limit($limit);
        } else {
            $query = DB::table('inventory.inventory_ledger as il')
                ->leftJoin('inventory.products as p', 'p.id', '=', 'il.product_id')
                ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'il.warehouse_id')
                ->leftJoin('inventory.product_lots as pl', 'pl.id', '=', 'il.lot_id')
                ->selectRaw('DATE(il.moved_at) as snapshot_date, il.warehouse_id, w.code as warehouse_code, w.name as warehouse_name, il.product_id, p.sku as product_sku, p.name as product_name, il.lot_id, pl.lot_code as lot_code, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity ELSE 0 END), 0) as qty_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity ELSE 0 END), 0) as qty_out, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity ELSE -il.quantity END), 0) as qty_net, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity * il.unit_cost ELSE 0 END), 0) as value_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity * il.unit_cost ELSE 0 END), 0) as value_out, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity * il.unit_cost ELSE -il.quantity * il.unit_cost END), 0) as value_net, COUNT(*) as movement_count, MIN(il.moved_at) as first_moved_at, MAX(il.moved_at) as last_moved_at')
                ->where('il.company_id', $companyId)
                ->whereBetween(DB::raw('DATE(il.moved_at)'), [$dateFrom, $dateTo])
                ->groupByRaw('DATE(il.moved_at), il.warehouse_id, w.code, w.name, il.product_id, p.sku, p.name, il.lot_id, pl.lot_code')
                ->orderByDesc(DB::raw('DATE(il.moved_at)'))
                ->orderBy('p.name')
                ->limit($limit);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            if ($advancedReporting) {
                $query->where('ds.warehouse_id', (int) $warehouseId);
            } else {
                $query->where('il.warehouse_id', (int) $warehouseId);
            }
        }
        if ($productId !== null && $productId !== '') {
            if ($advancedReporting) {
                $query->where('ds.product_id', (int) $productId);
            } else {
                $query->where('il.product_id', (int) $productId);
            }
        }

        $rows = $query->get();

        return response()->json([
            'data' => $rows,
            'profile' => [
                'advanced_reporting' => $advancedReporting,
                'source' => $advancedReporting ? 'projection' : 'ledger',
            ],
            'summary' => [
                'rows' => $rows->count(),
                'total_qty_in' => (float) $rows->sum('qty_in'),
                'total_qty_out' => (float) $rows->sum('qty_out'),
                'total_qty_net' => (float) $rows->sum('qty_net'),
                'total_value_in' => (float) $rows->sum('value_in'),
                'total_value_out' => (float) $rows->sum('value_out'),
                'total_value_net' => (float) $rows->sum('value_net'),
            ],
        ]);
    }

    public function lotExpiry(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $warehouseId = $request->query('warehouse_id');
        $productId = $request->query('product_id');
        $bucket = $request->query('bucket');
        $limit = min(max((int) $request->query('limit', 500), 1), 5000);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $settings = $this->inventorySettingsForCompany($companyId);
        $expiryEnabled = (bool) ($settings['enable_inventory_pro'] ?? false) && (bool) ($settings['enable_expiry_tracking'] ?? false);

        if (!$expiryEnabled) {
            return response()->json([
                'data' => [],
                'profile' => [
                    'expiry_tracking' => false,
                    'mode' => 'basic',
                ],
                'summary' => [
                    'rows' => 0,
                    'total_stock' => 0,
                    'total_value' => 0,
                ],
            ]);
        }

        ProjectionEngine::ensureSchema();
        ProjectionEngine::refreshLotExpiryProjection($companyId, $warehouseId !== null && $warehouseId !== '' ? (int) $warehouseId : null, $productId !== null && $productId !== '' ? (int) $productId : null);

        $query = DB::table('inventory.lot_expiry_projection as le')
            ->leftJoin('inventory.products as p', 'p.id', '=', 'le.product_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'le.warehouse_id')
            ->select([
                'le.company_id',
                'le.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                'le.product_id',
                DB::raw('p.sku as product_sku'),
                DB::raw('p.name as product_name'),
                'le.lot_id',
                'le.lot_code',
                'le.manufacture_at',
                'le.expires_at',
                'le.days_to_expire',
                'le.expiry_bucket',
                'le.stock',
                'le.unit_cost',
                'le.stock_value',
            ])
            ->where('le.company_id', $companyId)
            ->orderBy('le.expires_at')
            ->orderBy('p.name')
            ->limit($limit);

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('le.warehouse_id', (int) $warehouseId);
        }
        if ($productId !== null && $productId !== '') {
            $query->where('le.product_id', (int) $productId);
        }
        if ($bucket !== null && $bucket !== '') {
            $query->where('le.expiry_bucket', strtoupper((string) $bucket));
        }

        $rows = $query->get();

        return response()->json([
            'data' => $rows,
            'profile' => [
                'expiry_tracking' => true,
            ],
            'summary' => [
                'rows' => $rows->count(),
                'total_stock' => (float) $rows->sum('stock'),
                'total_value' => (float) $rows->sum('stock_value'),
            ],
        ]);
    }

    public function listRequests(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $status = $request->query('status');
        $reportType = $request->query('report_type');
        $limit = min(max((int) $request->query('limit', 50), 1), 200);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $settings = $this->inventorySettingsForCompany($companyId);

        ReportEngine::ensureSchema();

        $query = DB::table('inventory.report_requests')
            ->select([
                'id',
                'company_id',
                'branch_id',
                'requested_by',
                'report_type',
                'status',
                'error_message',
                'requested_at',
                'started_at',
                'finished_at',
            ])
            ->where('company_id', $companyId)
            ->orderByDesc('requested_at')
            ->limit($limit);

        if ($status !== null && $status !== '') {
            $query->where('status', strtoupper((string) $status));
        }
        if ($reportType !== null && $reportType !== '') {
            $query->where('report_type', strtoupper((string) $reportType));
        }

        $rows = $query->get()->filter(function ($row) use ($settings) {
            return $this->reportTypeAllowedForSettings((string) $row->report_type, $settings);
        })->values();

        return response()->json([
            'data' => $rows,
            'profile' => [
                'inventory_pro' => (bool) ($settings['enable_inventory_pro'] ?? false),
            ],
        ]);
    }

    public function createRequest(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'report_type' => 'required|string|in:STOCK_SNAPSHOT,KARDEX_PHYSICAL,KARDEX_VALUED,LOT_EXPIRY,INVENTORY_CUT',
            'filters' => 'nullable|array',
            'run_async' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $settings = $this->inventorySettingsForCompany($companyId);
        $reportType = strtoupper((string) $payload['report_type']);

        if (!$this->reportTypeAllowedForSettings($reportType, $settings)) {
            return response()->json([
                'message' => 'Report type is not available for current inventory profile',
            ], 422);
        }

        ReportEngine::ensureSchema();

        $requestId = DB::table('inventory.report_requests')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? $authUser->branch_id,
            'requested_by' => $authUser->id,
            'report_type' => $reportType,
            'filters_json' => json_encode($payload['filters'] ?? []),
            'status' => ReportEngine::STATUS_PENDING,
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runAsync = (bool) ($payload['run_async'] ?? true);
        $queueConnection = (string) config('queue.default', 'sync');

        if ($runAsync) {
            if ($queueConnection !== 'sync') {
                GenerateInventoryReportJob::dispatch((int) $requestId)->onQueue('inventory-reports');
                $mode = 'async';
            } else {
                $mode = 'deferred';
            }
        } else {
            ReportEngine::process((int) $requestId);
            $mode = 'inline';
        }

        $result = DB::table('inventory.report_requests')
            ->select([
                'id',
                'company_id',
                'branch_id',
                'requested_by',
                'report_type',
                'status',
                'error_message',
                'requested_at',
                'started_at',
                'finished_at',
            ])
            ->where('id', (int) $requestId)
            ->first();

        return response()->json([
            'message' => 'Report request created',
            'mode' => $mode,
            'queue_connection' => $queueConnection,
            'data' => $result,
        ], 201);
    }

    public function showRequest(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $settings = $this->inventorySettingsForCompany($companyId);

        ReportEngine::ensureSchema();

        $row = DB::table('inventory.report_requests')
            ->select([
                'id',
                'company_id',
                'branch_id',
                'requested_by',
                'report_type',
                'filters_json',
                'status',
                'result_json',
                'error_message',
                'requested_at',
                'started_at',
                'finished_at',
            ])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Report request not found'], 404);
        }

        if (!$this->reportTypeAllowedForSettings((string) $row->report_type, $settings)) {
            return response()->json(['message' => 'Report request not available for current inventory profile'], 422);
        }

        return response()->json([
            'data' => [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'requested_by' => (int) $row->requested_by,
                'report_type' => $row->report_type,
                'filters' => is_string($row->filters_json) ? (json_decode($row->filters_json, true) ?: []) : (array) $row->filters_json,
                'status' => $row->status,
                'result' => is_string($row->result_json) ? (json_decode($row->result_json, true) ?: null) : $row->result_json,
                'error_message' => $row->error_message,
                'requested_at' => $row->requested_at,
                'started_at' => $row->started_at,
                'finished_at' => $row->finished_at,
            ],
        ]);
    }
}
