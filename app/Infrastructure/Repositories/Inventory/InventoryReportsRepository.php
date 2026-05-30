<?php

namespace App\Infrastructure\Repositories\Inventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReportsRepository
{
    public function findInventorySettings(int $companyId): ?object
    {
        return DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();
    }

    public function findStockSummary(int $companyId, ?int $warehouseId): ?object
    {
        $query = DB::table('inventory.current_stock as cs')
            ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
            ->where('cs.company_id', $companyId);

        if ($warehouseId !== null) {
            $query->where('cs.warehouse_id', $warehouseId);
        }

        return $query
            ->selectRaw('COUNT(*) as rows, COALESCE(SUM(cs.stock), 0) as total_qty, COALESCE(SUM(cs.stock * p.cost_price), 0) as total_value')
            ->first();
    }

    public function listExpiryBuckets(int $companyId, ?int $warehouseId): Collection
    {
        $query = DB::table('inventory.lot_expiry_projection')
            ->where('company_id', $companyId);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query
            ->selectRaw('COALESCE(expiry_bucket, \'NO_EXPIRY\') as bucket, COUNT(*) as total_lots, COALESCE(SUM(stock), 0) as total_stock, COALESCE(SUM(stock_value), 0) as total_value')
            ->groupBy('bucket')
            ->get();
    }

    public function listMovementTrendAdvanced(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        $query = DB::table('inventory.stock_daily_snapshot as ds')
            ->where('ds.company_id', $companyId)
            ->where('ds.snapshot_date', '>=', $snapshotFrom);

        if ($warehouseId !== null) {
            $query->where('ds.warehouse_id', $warehouseId);
        }

        return $query
            ->selectRaw('snapshot_date, COALESCE(SUM(qty_in), 0) as qty_in, COALESCE(SUM(qty_out), 0) as qty_out, COALESCE(SUM(value_in), 0) as value_in, COALESCE(SUM(value_out), 0) as value_out')
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date')
            ->get();
    }

    public function listTopProductsAdvanced(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        $query = DB::table('inventory.stock_daily_snapshot as ds')
            ->join('inventory.products as p', 'p.id', '=', 'ds.product_id')
            ->where('ds.company_id', $companyId)
            ->where('ds.snapshot_date', '>=', $snapshotFrom);

        if ($warehouseId !== null) {
            $query->where('ds.warehouse_id', $warehouseId);
        }

        return $query
            ->selectRaw('ds.product_id, p.sku as product_sku, p.name as product_name, COALESCE(SUM(ds.qty_in), 0) as qty_in, COALESCE(SUM(ds.qty_out), 0) as qty_out, COALESCE(SUM(ABS(ds.value_net)), 0) as movement_value')
            ->groupBy('ds.product_id', 'p.sku', 'p.name')
            ->orderByDesc('movement_value')
            ->limit(10)
            ->get();
    }

    public function listMovementTrendBasic(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        $query = DB::table('inventory.inventory_ledger as il')
            ->where('il.company_id', $companyId)
            ->whereDate('il.moved_at', '>=', $snapshotFrom);

        if ($warehouseId !== null) {
            $query->where('il.warehouse_id', $warehouseId);
        }

        return $query
            ->selectRaw('DATE(il.moved_at) as snapshot_date, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity ELSE 0 END), 0) as qty_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity ELSE 0 END), 0) as qty_out, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity * il.unit_cost ELSE 0 END), 0) as value_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity * il.unit_cost ELSE 0 END), 0) as value_out')
            ->groupByRaw('DATE(il.moved_at)')
            ->orderBy('snapshot_date')
            ->get();
    }

    public function listTopProductsBasic(int $companyId, string $snapshotFrom, ?int $warehouseId): Collection
    {
        $query = DB::table('inventory.inventory_ledger as il')
            ->join('inventory.products as p', 'p.id', '=', 'il.product_id')
            ->where('il.company_id', $companyId)
            ->whereDate('il.moved_at', '>=', $snapshotFrom);

        if ($warehouseId !== null) {
            $query->where('il.warehouse_id', $warehouseId);
        }

        return $query
            ->selectRaw('il.product_id, p.sku as product_sku, p.name as product_name, COALESCE(SUM(CASE WHEN il.movement_type = \'IN\' THEN il.quantity ELSE 0 END), 0) as qty_in, COALESCE(SUM(CASE WHEN il.movement_type = \'OUT\' THEN il.quantity ELSE 0 END), 0) as qty_out, COALESCE(SUM(ABS(il.quantity * il.unit_cost)), 0) as movement_value')
            ->groupBy('il.product_id', 'p.sku', 'p.name')
            ->orderByDesc('movement_value')
            ->limit(10)
            ->get();
    }

    public function listDailySnapshotAdvanced(
        int $companyId,
        string $dateFrom,
        string $dateTo,
        ?int $warehouseId,
        ?int $productId,
        int $limit
    ): Collection {
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

        if ($warehouseId !== null) {
            $query->where('ds.warehouse_id', $warehouseId);
        }

        if ($productId !== null) {
            $query->where('ds.product_id', $productId);
        }

        return $query->get();
    }

    public function listDailySnapshotBasic(
        int $companyId,
        string $dateFrom,
        string $dateTo,
        ?int $warehouseId,
        ?int $productId,
        int $limit
    ): Collection {
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

        if ($warehouseId !== null) {
            $query->where('il.warehouse_id', $warehouseId);
        }

        if ($productId !== null) {
            $query->where('il.product_id', $productId);
        }

        return $query->get();
    }

    public function listLotExpiryRows(
        int $companyId,
        ?int $warehouseId,
        ?int $productId,
        ?string $bucket,
        int $limit
    ): Collection {
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

        if ($warehouseId !== null) {
            $query->where('le.warehouse_id', $warehouseId);
        }

        if ($productId !== null) {
            $query->where('le.product_id', $productId);
        }

        if ($bucket !== null && $bucket !== '') {
            $query->where('le.expiry_bucket', strtoupper($bucket));
        }

        return $query->get();
    }

    public function listReportRequests(int $companyId, ?string $status, ?string $reportType, int $limit): Collection
    {
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
            $query->where('status', strtoupper($status));
        }

        if ($reportType !== null && $reportType !== '') {
            $query->where('report_type', strtoupper($reportType));
        }

        return $query->get();
    }

    public function createReportRequest(array $payload): int
    {
        return (int) DB::table('inventory.report_requests')->insertGetId($payload);
    }

    public function findReportRequestById(int $id): ?object
    {
        return DB::table('inventory.report_requests')
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
            ->where('id', $id)
            ->first();
    }

    public function findReportRequestByCompany(int $id, int $companyId): ?object
    {
        return DB::table('inventory.report_requests')
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
    }
}
