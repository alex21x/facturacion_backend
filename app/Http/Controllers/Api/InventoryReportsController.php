<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateInventoryReportJob;
use App\Services\Inventory\InventoryReportsService;
use App\Support\Inventory\ProjectionEngine;
use App\Support\Inventory\ReportEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryReportsController extends Controller
{
    public function __construct(private InventoryReportsService $inventoryReportsService)
    {
    }

    private function ensureInventoryProEnabled(int $companyId, bool $requireAdvancedReporting = false, bool $requireDashboard = false, bool $requireExpiry = false): void
    {
        $this->inventoryReportsService->ensureInventoryProEnabled(
            $companyId,
            $requireAdvancedReporting,
            $requireDashboard,
            $requireExpiry
        );
    }

    private function reportTypeAllowedForSettings(string $reportType, array $settings): bool
    {
        return $this->inventoryReportsService->reportTypeAllowedForSettings($reportType, $settings);
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

        $settings = $this->inventoryReportsService->inventorySettingsForCompany($companyId);
        $inventoryPro = (bool) ($settings['enable_inventory_pro'] ?? false);
        $advancedReporting = $inventoryPro && (bool) ($settings['enable_advanced_reporting'] ?? false);
        $expiryEnabled = $inventoryPro && (bool) ($settings['enable_expiry_tracking'] ?? false);

        ProjectionEngine::ensureSchema();
        if ($expiryEnabled) {
            ProjectionEngine::refreshLotExpiryProjection($companyId, $warehouseId !== null && $warehouseId !== '' ? (int) $warehouseId : null);
        }

        $snapshotFrom = now()->subDays($days - 1)->toDateString();

        $resolvedWarehouseId = $warehouseId !== null && $warehouseId !== '' ? (int) $warehouseId : null;
        $stockSummary = $this->inventoryReportsService->stockSummary($companyId, $resolvedWarehouseId);

        $expiryBuckets = collect();
        if ($expiryEnabled) {
            $expiryBuckets = $this->inventoryReportsService->expiryBuckets($companyId, $resolvedWarehouseId);
        }

        if ($advancedReporting) {
            $movementTrend = $this->inventoryReportsService->movementTrendAdvanced($companyId, $snapshotFrom, $resolvedWarehouseId);
            $topProducts = $this->inventoryReportsService->topProductsAdvanced($companyId, $snapshotFrom, $resolvedWarehouseId);
        } else {
            $movementTrend = $this->inventoryReportsService->movementTrendBasic($companyId, $snapshotFrom, $resolvedWarehouseId);
            $topProducts = $this->inventoryReportsService->topProductsBasic($companyId, $snapshotFrom, $resolvedWarehouseId);
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

        $settings = $this->inventoryReportsService->inventorySettingsForCompany($companyId);
        $advancedReporting = (bool) ($settings['enable_inventory_pro'] ?? false) && (bool) ($settings['enable_advanced_reporting'] ?? false);

        if ($advancedReporting) {
            ProjectionEngine::ensureSchema();
        }

        $resolvedWarehouseId = $warehouseId !== null && $warehouseId !== '' ? (int) $warehouseId : null;
        $resolvedProductId = $productId !== null && $productId !== '' ? (int) $productId : null;

        $rows = $this->inventoryReportsService->dailySnapshotRows(
            $companyId,
            $dateFrom,
            $dateTo,
            $resolvedWarehouseId,
            $resolvedProductId,
            $limit,
            $advancedReporting
        );

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

        $settings = $this->inventoryReportsService->inventorySettingsForCompany($companyId);
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

        $resolvedWarehouseId = $warehouseId !== null && $warehouseId !== '' ? (int) $warehouseId : null;
        $resolvedProductId = $productId !== null && $productId !== '' ? (int) $productId : null;
        $resolvedBucket = $bucket !== null && $bucket !== '' ? (string) $bucket : null;

        $rows = $this->inventoryReportsService->lotExpiryRows(
            $companyId,
            $resolvedWarehouseId,
            $resolvedProductId,
            $resolvedBucket,
            $limit
        );

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

        $settings = $this->inventoryReportsService->inventorySettingsForCompany($companyId);

        ReportEngine::ensureSchema();

        $rows = $this->inventoryReportsService
            ->listRequests(
                $companyId,
                $status !== null && $status !== '' ? (string) $status : null,
                $reportType !== null && $reportType !== '' ? (string) $reportType : null,
                $limit
            )
            ->filter(function ($row) use ($settings) {
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
        $settings = $this->inventoryReportsService->inventorySettingsForCompany($companyId);
        $reportType = strtoupper((string) $payload['report_type']);

        if (!$this->reportTypeAllowedForSettings($reportType, $settings)) {
            return response()->json([
                'message' => 'Report type is not available for current inventory profile',
            ], 422);
        }

        ReportEngine::ensureSchema();

        $requestId = $this->inventoryReportsService->createRequest([
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

        if ($runAsync && $queueConnection !== 'sync') {
            GenerateInventoryReportJob::dispatch((int) $requestId)->onQueue('inventory-reports');
            $mode = 'async';
        } else {
            // Cola sync o ejecucion en linea: procesar de inmediato
            ReportEngine::process((int) $requestId);
            $mode = 'inline';
        }

        $result = $this->inventoryReportsService->findRequestById((int) $requestId);

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

        $settings = $this->inventoryReportsService->inventorySettingsForCompany($companyId);

        ReportEngine::ensureSchema();

        $row = $this->inventoryReportsService->findRequestByCompany($id, $companyId);

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
