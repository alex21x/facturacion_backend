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
            case 'SALES_DOCUMENTS_SUMMARY':
                return self::salesDocumentsSummary($companyId, $filters);
            case 'SALES_SUNAT_MONITOR':
                return self::salesSunatMonitor($companyId, $filters);
            default:
                throw new \RuntimeException('Unsupported report type');
        }
    }

    private static function salesDocumentsSummary(int $companyId, array $filters): array
    {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->select([
                'd.id',
                'd.branch_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                DB::raw("COALESCE((d.metadata->>'sunat_status'), '') as sunat_status"),
                DB::raw("COALESCE((d.metadata->>'sunat_void_status'), '') as sunat_void_status"),
                DB::raw("NULLIF((d.metadata->>'sunat_summary_id'), '')::BIGINT as sunat_summary_id"),
                DB::raw("NULLIF((d.metadata->>'sunat_void_summary_id'), '')::BIGINT as sunat_void_summary_id"),
                'd.total',
                'd.balance_due',
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
            ])
            ->where('d.company_id', $companyId)
            ->orderByDesc('d.issue_at')
            ->orderByDesc('d.id')
            ->limit(50000);

        self::applySalesFilters($query, $filters);

        $rows = $query->get();

        return [
            'type' => 'SALES_DOCUMENTS_SUMMARY',
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'total_rows' => $rows->count(),
                'total_amount' => (float) $rows->sum('total'),
                'issued_count' => (int) $rows->where('status', 'ISSUED')->count(),
                'void_count' => (int) $rows->where('status', 'VOID')->count(),
                'accepted_count' => (int) $rows->where('sunat_status', 'ACCEPTED')->count(),
                'pending_confirmation_count' => (int) $rows->where('sunat_status', 'PENDING_CONFIRMATION')->count(),
                'rejected_count' => (int) $rows->where('sunat_status', 'REJECTED')->count(),
            ],
        ];
    }

    private static function salesSunatMonitor(int $companyId, array $filters): array
    {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->select([
                'd.id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                DB::raw("COALESCE((d.metadata->>'sunat_status'), '') as sunat_status"),
                DB::raw("COALESCE((d.metadata->>'sunat_status_label'), '') as sunat_status_label"),
                DB::raw("COALESCE((d.metadata->>'sunat_void_status'), '') as sunat_void_status"),
                DB::raw("COALESCE((d.metadata->>'sunat_void_label'), '') as sunat_void_label"),
                DB::raw("COALESCE((d.metadata->>'sunat_ticket'), '') as sunat_ticket"),
                DB::raw("COALESCE((d.metadata->>'sunat_reconcile_attempts'), '0')::INT as reconcile_attempts"),
                DB::raw("COALESCE((d.metadata->>'sunat_reconcile_next_at'), '') as reconcile_next_at"),
                DB::raw("COALESCE((d.metadata->>'sunat_needs_manual_confirmation'), 'false') as needs_manual_confirmation"),
                'd.total',
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
            ])
            ->where('d.company_id', $companyId)
            ->whereIn('d.document_kind', ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'])
            ->where('d.status', 'ISSUED')
            ->where(function ($q) {
                $q->whereRaw("UPPER(COALESCE(d.metadata->>'sunat_status','')) IN ('PENDING_CONFIRMATION', 'HTTP_ERROR', 'NETWORK_ERROR', 'REJECTED', 'EXPIRED_WINDOW')")
                  ->orWhereRaw("UPPER(COALESCE(d.metadata->>'sunat_void_status','')) IN ('PENDING_SUMMARY', 'SENT_BY_SUMMARY', 'REJECTED')");
            })
            ->orderByDesc('d.issue_at')
            ->orderByDesc('d.id')
            ->limit(50000);

        self::applySalesFilters($query, $filters);

        $rows = $query->get();

        return [
            'type' => 'SALES_SUNAT_MONITOR',
            'generated_at' => now()->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'total_rows' => $rows->count(),
                'pending_confirmation_count' => (int) $rows->where('sunat_status', 'PENDING_CONFIRMATION')->count(),
                'rejected_count' => (int) $rows->where('sunat_status', 'REJECTED')->count(),
                'expired_window_count' => (int) $rows->where('sunat_status', 'EXPIRED_WINDOW')->count(),
                'manual_confirmation_required' => (int) $rows->filter(function ($row) {
                    return strtolower((string) ($row->needs_manual_confirmation ?? 'false')) === 'true';
                })->count(),
            ],
        ];
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

    private static function applySalesFilters($query, array $filters): void
    {
        if (!empty($filters['branch_id'])) {
            $query->where('d.branch_id', (int) $filters['branch_id']);
        }
        if (!empty($filters['document_kind'])) {
            $query->where('d.document_kind', strtoupper((string) $filters['document_kind']));
        }
        if (!empty($filters['status'])) {
            $query->where('d.status', strtoupper((string) $filters['status']));
        }
        if (!empty($filters['issue_date_from'])) {
            $query->whereDate('d.issue_at', '>=', (string) $filters['issue_date_from']);
        }
        if (!empty($filters['issue_date_to'])) {
            $query->whereDate('d.issue_at', '<=', (string) $filters['issue_date_to']);
        }
        if (!empty($filters['customer'])) {
            $text = '%' . mb_strtolower((string) $filters['customer']) . '%';
            $query->whereRaw("LOWER(COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))) LIKE ?", [$text]);
        }
    }
}
