<?php

namespace App\Support\Inventory;

use Illuminate\Support\Facades\DB;

class ReportEngine
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    public static function ensureSchema(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.report_requests (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL,
            branch_id BIGINT NULL,
            requested_by BIGINT NOT NULL,
            report_type VARCHAR(40) NOT NULL,
            filters_json JSONB NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'PENDING\',
            result_json JSONB NULL,
            error_message TEXT NULL,
            requested_at TIMESTAMPTZ NOT NULL,
            started_at TIMESTAMPTZ NULL,
            finished_at TIMESTAMPTZ NULL,
            created_at TIMESTAMPTZ NULL,
            updated_at TIMESTAMPTZ NULL
        )');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_report_requests_company_status ON inventory.report_requests (company_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_report_requests_company_type ON inventory.report_requests (company_id, report_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_report_requests_requested_at ON inventory.report_requests (requested_at DESC)');
    }

    public static function process(int $requestId): void
    {
        self::ensureSchema();

        $request = DB::table('inventory.report_requests')->where('id', $requestId)->first();
        if (!$request) {
            return;
        }

        DB::table('inventory.report_requests')
            ->where('id', $requestId)
            ->update([
                'status' => self::STATUS_PROCESSING,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        try {
            $filters = is_string($request->filters_json)
                ? (json_decode($request->filters_json, true) ?: [])
                : ((array) $request->filters_json);

            $companyId = (int) $request->company_id;
            $result = self::buildReport((string) $request->report_type, $companyId, is_array($filters) ? $filters : []);

            DB::table('inventory.report_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => self::STATUS_COMPLETED,
                    'result_json' => json_encode($result),
                    'error_message' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            DB::table('inventory.report_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => self::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 1500),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private static function buildReport(string $reportType, int $companyId, array $filters): array
    {
        switch ($reportType) {
            case 'STOCK_SNAPSHOT':
                return self::stockSnapshot($companyId, $filters);
            case 'KARDEX_PHYSICAL':
                return self::kardexPhysical($companyId, $filters);
            case 'KARDEX_VALUED':
                return self::kardexValued($companyId, $filters);
            case 'LOT_EXPIRY':
                return self::lotExpiry($companyId, $filters);
            case 'INVENTORY_CUT':
                return self::inventoryCut($companyId, $filters);
            default:
                throw new \RuntimeException('Unsupported report type');
        }
    }

    private static function stockSnapshot(int $companyId, array $filters): array
    {
        $query = DB::table('inventory.current_stock as cs')
            ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'cs.warehouse_id')
            ->select([
                'cs.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                'cs.product_id',
                DB::raw('p.sku as product_sku'),
                DB::raw('p.name as product_name'),
                DB::raw('cs.stock as qty'),
                DB::raw('p.cost_price as unit_cost'),
                DB::raw('(cs.stock * p.cost_price) as total_value'),
            ])
            ->where('cs.company_id', $companyId)
            ->orderBy('p.name')
            ->limit(30000);

        if (!empty($filters['warehouse_id'])) {
            $query->where('cs.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['product_id'])) {
            $query->where('cs.product_id', (int) $filters['product_id']);
        }

        $rows = $query->get();

        return [
            'type' => 'STOCK_SNAPSHOT',
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'total_rows' => $rows->count(),
                'total_qty' => (float) $rows->sum('qty'),
                'total_value' => (float) $rows->sum('total_value'),
            ],
        ];
    }

    private static function kardexPhysical(int $companyId, array $filters): array
    {
        $query = DB::table('inventory.inventory_ledger as il')
            ->leftJoin('inventory.products as p', 'p.id', '=', 'il.product_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'il.warehouse_id')
            ->leftJoin('inventory.product_lots as pl', 'pl.id', '=', 'il.lot_id')
            ->select([
                'il.id',
                'il.moved_at',
                'il.movement_type',
                'il.quantity',
                'il.product_id',
                DB::raw('p.sku as product_sku'),
                DB::raw('p.name as product_name'),
                'il.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                'il.lot_id',
                DB::raw('pl.lot_code as lot_code'),
                'il.ref_type',
                'il.ref_id',
            ])
            ->where('il.company_id', $companyId)
            ->orderByDesc('il.moved_at')
            ->orderByDesc('il.id')
            ->limit(50000);

        self::applyKardexFilters($query, $filters);

        $rows = $query->get();

        return [
            'type' => 'KARDEX_PHYSICAL',
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'total_rows' => $rows->count(),
                'total_qty' => (float) $rows->sum('quantity'),
            ],
        ];
    }

    private static function kardexValued(int $companyId, array $filters): array
    {
        $query = DB::table('inventory.inventory_ledger as il')
            ->leftJoin('inventory.products as p', 'p.id', '=', 'il.product_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'il.warehouse_id')
            ->leftJoin('inventory.product_lots as pl', 'pl.id', '=', 'il.lot_id')
            ->select([
                'il.id',
                'il.moved_at',
                'il.movement_type',
                'il.quantity',
                'il.unit_cost',
                DB::raw('(il.quantity * il.unit_cost) as line_total'),
                'il.product_id',
                DB::raw('p.sku as product_sku'),
                DB::raw('p.name as product_name'),
                'il.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                'il.lot_id',
                DB::raw('pl.lot_code as lot_code'),
                'il.ref_type',
                'il.ref_id',
            ])
            ->where('il.company_id', $companyId)
            ->orderByDesc('il.moved_at')
            ->orderByDesc('il.id')
            ->limit(50000);

        self::applyKardexFilters($query, $filters);

        $rows = $query->get();

        return [
            'type' => 'KARDEX_VALUED',
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'total_rows' => $rows->count(),
                'total_qty' => (float) $rows->sum('quantity'),
                'total_value' => (float) $rows->sum('line_total'),
            ],
        ];
    }

    private static function lotExpiry(int $companyId, array $filters): array
    {
        $query = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'pl.warehouse_id')
            ->leftJoin('inventory.current_stock_by_lot as sl', function ($join) {
                $join->on('sl.company_id', '=', 'pl.company_id')
                    ->on('sl.warehouse_id', '=', 'pl.warehouse_id')
                    ->on('sl.product_id', '=', 'pl.product_id')
                    ->on('sl.lot_id', '=', 'pl.id');
            })
            ->select([
                'pl.id',
                'pl.lot_code',
                'pl.product_id',
                DB::raw('p.sku as product_sku'),
                DB::raw('p.name as product_name'),
                'pl.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                'pl.manufacture_at',
                'pl.expires_at',
                DB::raw('COALESCE(sl.stock, 0) as stock'),
                DB::raw('CASE WHEN pl.expires_at IS NULL THEN NULL ELSE FLOOR(EXTRACT(EPOCH FROM (pl.expires_at::timestamp - NOW())) / 86400) END as days_to_expire'),
            ])
            ->where('pl.company_id', $companyId)
            ->orderBy('pl.expires_at')
            ->limit(30000);

        if (!empty($filters['warehouse_id'])) {
            $query->where('pl.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['product_id'])) {
            $query->where('pl.product_id', (int) $filters['product_id']);
        }
        if (array_key_exists('only_with_stock', $filters) && (bool) $filters['only_with_stock']) {
            $query->whereRaw('COALESCE(sl.stock, 0) > 0');
        }

        $rows = $query->get();

        return [
            'type' => 'LOT_EXPIRY',
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'total_rows' => $rows->count(),
                'expired_rows' => $rows->filter(function ($row) {
                    return $row->days_to_expire !== null && (int) $row->days_to_expire < 0;
                })->count(),
            ],
        ];
    }

    private static function inventoryCut(int $companyId, array $filters): array
    {
        $query = DB::table('inventory.current_stock as cs')
            ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'cs.warehouse_id')
            ->select([
                'cs.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                DB::raw('COUNT(*) as product_rows'),
                DB::raw('SUM(cs.stock) as total_qty'),
                DB::raw('SUM(cs.stock * p.cost_price) as total_value'),
            ])
            ->where('cs.company_id', $companyId)
            ->groupBy('cs.warehouse_id', 'w.code', 'w.name')
            ->orderBy('w.name');

        if (!empty($filters['warehouse_id'])) {
            $query->where('cs.warehouse_id', (int) $filters['warehouse_id']);
        }

        $rows = $query->get();

        return [
            'type' => 'INVENTORY_CUT',
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'warehouses' => $rows->count(),
                'total_qty' => (float) $rows->sum('total_qty'),
                'total_value' => (float) $rows->sum('total_value'),
            ],
        ];
    }

    private static function applyKardexFilters($query, array $filters): void
    {
        if (!empty($filters['warehouse_id'])) {
            $query->where('il.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['product_id'])) {
            $query->where('il.product_id', (int) $filters['product_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('il.moved_at', '>=', (string) $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('il.moved_at', '<=', (string) $filters['date_to'] . ' 23:59:59');
        }
    }
}
