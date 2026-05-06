<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Domain\Inventory\Repositories\InventoryReadRepositoryInterface;
use Illuminate\Support\Facades\DB;

class InventoryReadRepository implements InventoryReadRepositoryInterface
{
    public function getCurrentStock(int $companyId, $warehouseId, $productId): array
    {
        $query = DB::table('inventory.current_stock as cs')
            ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'cs.warehouse_id')
            ->select([
                'cs.company_id',
                'cs.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'cs.product_id',
                'p.sku',
                'p.name as product_name',
                'cs.stock',
            ])
            ->where('cs.company_id', $companyId)
            ->orderBy('p.name');

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('cs.warehouse_id', (int) $warehouseId);
        }

        if ($productId !== null && $productId !== '') {
            $query->where('cs.product_id', (int) $productId);
        }

        return $query->get()->all();
    }

    public function getLots(int $companyId, $warehouseId, $productId, bool $onlyWithStock): array
    {
        $query = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'pl.warehouse_id')
            ->leftJoin('inventory.current_stock_by_lot as sl', function ($join) {
                $join->on('sl.lot_id', '=', 'pl.id')
                    ->on('sl.product_id', '=', 'pl.product_id')
                    ->on('sl.warehouse_id', '=', 'pl.warehouse_id')
                    ->on('sl.company_id', '=', 'pl.company_id');
            })
            ->select([
                'pl.id',
                'pl.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'pl.product_id',
                'p.sku',
                'p.name as product_name',
                'pl.lot_code',
                'pl.manufacture_at',
                'pl.expires_at',
                'pl.received_at',
                'pl.status',
                DB::raw('COALESCE(sl.stock, 0) as stock'),
            ])
            ->where('pl.company_id', $companyId)
            ->orderBy('p.name')
            ->orderBy('pl.lot_code');

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('pl.warehouse_id', (int) $warehouseId);
        }

        if ($productId !== null && $productId !== '') {
            $query->where('pl.product_id', (int) $productId);
        }

        if ($onlyWithStock) {
            $query->whereRaw('COALESCE(sl.stock, 0) > 0');
        }

        return $query->get()->all();
    }

    public function getStockEntries(int $companyId, $warehouseId, $entryType, int $limit): array
    {
        $this->ensureStockEntriesTables();

        $summarySubquery = DB::table('inventory.stock_entry_items')
            ->selectRaw('entry_id, COUNT(*) as total_items, COALESCE(SUM(qty), 0) as total_qty, COALESCE(SUM(qty * unit_cost), 0) as total_amount')
            ->groupBy('entry_id');

        $query = DB::table('inventory.stock_entries as e')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'e.warehouse_id')
            ->leftJoinSub($summarySubquery, 's', function ($join) {
                $join->on('s.entry_id', '=', 'e.id');
            })
            ->select([
                'e.id',
                'e.company_id',
                'e.branch_id',
                'e.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'e.entry_type',
                'e.reference_no',
                'e.supplier_reference',
                'e.issue_at',
                'e.status',
                'e.notes',
                DB::raw('COALESCE(s.total_items, 0) as total_items'),
                DB::raw('COALESCE(s.total_qty, 0) as total_qty'),
                DB::raw('COALESCE(s.total_amount, 0) as total_amount'),
                'e.created_at',
            ])
            ->where('e.company_id', $companyId)
            ->orderByDesc('e.issue_at')
            ->orderByDesc('e.id')
            ->limit($limit);

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('e.warehouse_id', (int) $warehouseId);
        }

        if ($entryType !== null && $entryType !== '') {
            $query->where('e.entry_type', strtoupper((string) $entryType));
        }

        return $query->get()->all();
    }

    public function getKardex(int $companyId, $productId, $warehouseId, $dateFrom, $dateTo, int $perPage, int $page): array
    {
        $baseCostExpr = 'COALESCE(NULLIF(il.unit_cost, 0), NULLIF(pl.unit_cost, 0), NULLIF(p.cost_price, 0), 0)';

        $baseQuery = DB::table('inventory.inventory_ledger as il')
            ->leftJoin('inventory.products as p', 'p.id', '=', 'il.product_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'il.warehouse_id')
            ->leftJoin('inventory.product_lots as pl', 'pl.id', '=', 'il.lot_id')
            ->leftJoin('inventory.stock_entries as se', function ($join) {
                $join->on('se.id', '=', 'il.ref_id')
                    ->whereIn('il.ref_type', ['STOCK_ENTRY', 'STOCK_ENTRY_EDIT']);
            })
            ->select([
                'il.id',
                'il.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                'il.product_id',
                DB::raw('p.sku as product_sku'),
                DB::raw('p.name as product_name'),
                'il.lot_id',
                DB::raw('pl.lot_code'),
                'il.movement_type',
                'il.quantity',
                DB::raw($baseCostExpr . ' as unit_cost'),
                DB::raw('(il.quantity * ' . $baseCostExpr . ') as line_total'),
                DB::raw("SUM(CASE WHEN il.movement_type = 'IN' THEN il.quantity ELSE -il.quantity END)
                    OVER (
                        PARTITION BY il.company_id, il.warehouse_id, il.product_id
                        ORDER BY il.moved_at ASC, il.id ASC
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) as stock_balance"),
                'il.ref_type',
                'il.ref_id',
                DB::raw('se.entry_type as stock_entry_type'),
                DB::raw('se.reference_no as stock_entry_reference_no'),
                DB::raw('se.supplier_reference as stock_entry_supplier_reference'),
                'il.notes',
                'il.moved_at',
            ])
            ->where('il.company_id', $companyId);

        if ($productId !== null && $productId !== '') {
            $baseQuery->where('il.product_id', (int) $productId);
        }
        if ($warehouseId !== null && $warehouseId !== '') {
            $baseQuery->where('il.warehouse_id', (int) $warehouseId);
        }

        $query = DB::query()->fromSub($baseQuery, 'k');

        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where('k.moved_at', '>=', $dateFrom);
        }
        if ($dateTo !== null && $dateTo !== '') {
            $query->where('k.moved_at', '<=', $dateTo . ' 23:59:59');
        }

        $query
            ->orderByDesc('k.id')
            ->orderByDesc('k.moved_at');

        $total  = (clone $query)->count();
        $offset = ($page - 1) * $perPage;
        $rows   = $query->offset($offset)->limit($perPage)->get()->all();

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'total_pages'  => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    private function ensureStockEntriesTables(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.stock_entries (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                branch_id BIGINT NULL,
                warehouse_id BIGINT NOT NULL,
                entry_type VARCHAR(20) NOT NULL,
                reference_no VARCHAR(60) NULL,
                supplier_reference VARCHAR(120) NULL,
                issue_at TIMESTAMPTZ NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'APPLIED\',
                notes VARCHAR(300) NULL,
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                created_at TIMESTAMPTZ NULL,
                updated_at TIMESTAMPTZ NULL
            )'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS stock_entries_company_issue_idx
                ON inventory.stock_entries (company_id, issue_at DESC, id DESC)'
        );

        DB::statement('ALTER TABLE inventory.stock_entries ADD COLUMN IF NOT EXISTS payment_method_id BIGINT NULL');
        DB::statement('ALTER TABLE inventory.stock_entries ADD COLUMN IF NOT EXISTS metadata JSONB NULL');

        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.stock_entry_items (
                id BIGSERIAL PRIMARY KEY,
                entry_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                lot_id BIGINT NULL,
                qty NUMERIC(18,8) NOT NULL,
                unit_cost NUMERIC(18,8) NOT NULL DEFAULT 0,
                notes VARCHAR(200) NULL,
                created_at TIMESTAMPTZ NULL
            )'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS stock_entry_items_entry_idx
                ON inventory.stock_entry_items (entry_id)'
        );

        DB::statement('ALTER TABLE inventory.stock_entry_items ADD COLUMN IF NOT EXISTS tax_category_id BIGINT NULL');
        DB::statement('ALTER TABLE inventory.stock_entry_items ADD COLUMN IF NOT EXISTS tax_rate NUMERIC(8,4) NOT NULL DEFAULT 0');
    }
}
