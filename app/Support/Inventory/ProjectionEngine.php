<?php

namespace App\Support\Inventory;

use Illuminate\Support\Facades\DB;

class ProjectionEngine
{
    public static function ensureSchema(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.stock_daily_snapshot (
            snapshot_date DATE NOT NULL,
            company_id BIGINT NOT NULL,
            branch_id BIGINT NULL,
            warehouse_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            lot_id BIGINT NULL,
            qty_in NUMERIC(18,8) NOT NULL DEFAULT 0,
            qty_out NUMERIC(18,8) NOT NULL DEFAULT 0,
            qty_net NUMERIC(18,8) NOT NULL DEFAULT 0,
            value_in NUMERIC(18,8) NOT NULL DEFAULT 0,
            value_out NUMERIC(18,8) NOT NULL DEFAULT 0,
            value_net NUMERIC(18,8) NOT NULL DEFAULT 0,
            movement_count INT NOT NULL DEFAULT 0,
            first_moved_at TIMESTAMPTZ NULL,
            last_moved_at TIMESTAMPTZ NULL,
            updated_at TIMESTAMPTZ NULL,
            PRIMARY KEY (snapshot_date, company_id, warehouse_id, product_id, lot_id)
        )');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_stock_daily_company_date ON inventory.stock_daily_snapshot (company_id, snapshot_date DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_stock_daily_company_wh ON inventory.stock_daily_snapshot (company_id, warehouse_id, snapshot_date DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_stock_daily_company_product ON inventory.stock_daily_snapshot (company_id, product_id, snapshot_date DESC)');

        DB::statement('CREATE TABLE IF NOT EXISTS inventory.lot_expiry_projection (
            company_id BIGINT NOT NULL,
            warehouse_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            lot_id BIGINT NOT NULL,
            branch_id BIGINT NULL,
            lot_code VARCHAR(80) NOT NULL,
            manufacture_at DATE NULL,
            expires_at DATE NULL,
            stock NUMERIC(18,8) NOT NULL DEFAULT 0,
            unit_cost NUMERIC(18,8) NOT NULL DEFAULT 0,
            stock_value NUMERIC(18,8) NOT NULL DEFAULT 0,
            expiry_bucket VARCHAR(20) NULL,
            days_to_expire INT NULL,
            updated_at TIMESTAMPTZ NULL,
            PRIMARY KEY (company_id, warehouse_id, product_id, lot_id)
        )');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_lot_expiry_company_expiry ON inventory.lot_expiry_projection (company_id, expires_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_lot_expiry_company_wh ON inventory.lot_expiry_projection (company_id, warehouse_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_lot_expiry_company_product ON inventory.lot_expiry_projection (company_id, product_id)');
    }

    public static function consume(string $eventType, array $payload): void
    {
        self::ensureSchema();

        if ($eventType === 'INVENTORY_MOVEMENT_APPLIED') {
            self::applyInventoryMovement($payload);
            self::refreshLotExpiryProjection(
                (int) ($payload['company_id'] ?? 0),
                isset($payload['warehouse_id']) ? (int) $payload['warehouse_id'] : null,
                isset($payload['product_id']) ? (int) $payload['product_id'] : null,
                isset($payload['lot_id']) && $payload['lot_id'] !== null ? (int) $payload['lot_id'] : null
            );
        }
    }

    public static function rebuildDailySnapshot(?int $companyId = null, ?string $dateFrom = null): array
    {
        self::ensureSchema();

        $deleteQuery = DB::table('inventory.stock_daily_snapshot');
        if ($companyId) {
            $deleteQuery->where('company_id', $companyId);
        }
        if ($dateFrom) {
            $deleteQuery->where('snapshot_date', '>=', $dateFrom);
        }
        $deleted = $deleteQuery->delete();

        $ledger = DB::table('inventory.inventory_ledger as il')
            ->join('inventory.stock_entries as se', function ($join) {
                $join->on('se.id', '=', 'il.ref_id')
                    ->where('il.ref_type', '=', 'STOCK_ENTRY');
            })
            ->select([
                DB::raw('DATE(il.moved_at) as snapshot_date'),
                'il.company_id',
                'se.branch_id',
                'il.warehouse_id',
                'il.product_id',
                'il.lot_id',
                'il.movement_type',
                'il.quantity',
                'il.unit_cost',
                'il.moved_at',
            ]);

        if ($companyId) {
            $ledger->where('il.company_id', $companyId);
        }
        if ($dateFrom) {
            $ledger->where('il.moved_at', '>=', $dateFrom);
        }

        $rows = $ledger->orderBy('il.moved_at')->get();

        foreach ($rows as $row) {
            self::applyInventoryMovement([
                'company_id' => (int) $row->company_id,
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
                'lot_id' => $row->lot_id ? (int) $row->lot_id : null,
                'movement_type' => (string) $row->movement_type,
                'quantity' => (float) $row->quantity,
                'unit_cost' => (float) $row->unit_cost,
                'moved_at' => (string) $row->moved_at,
            ]);
        }

        self::refreshLotExpiryProjection($companyId ?? 0, null, null, null, $companyId !== null);

        return [
            'deleted' => (int) $deleted,
            'inserted' => $rows->count(),
        ];
    }

    public static function refreshLotExpiryProjection(
        int $companyId,
        ?int $warehouseId = null,
        ?int $productId = null,
        ?int $lotId = null,
        bool $allowZeroCompany = false
    ): void {
        self::ensureSchema();

        if ($companyId <= 0 && !$allowZeroCompany) {
            return;
        }

        $deleteQuery = DB::table('inventory.lot_expiry_projection');
        if ($companyId > 0) {
            $deleteQuery->where('company_id', $companyId);
        }
        if ($warehouseId) {
            $deleteQuery->where('warehouse_id', $warehouseId);
        }
        if ($productId) {
            $deleteQuery->where('product_id', $productId);
        }
        if ($lotId) {
            $deleteQuery->where('lot_id', $lotId);
        }
        $deleteQuery->delete();

        $query = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->leftJoin('inventory.current_stock_by_lot as sl', function ($join) {
                $join->on('sl.company_id', '=', 'pl.company_id')
                    ->on('sl.warehouse_id', '=', 'pl.warehouse_id')
                    ->on('sl.product_id', '=', 'pl.product_id')
                    ->on('sl.lot_id', '=', 'pl.id');
            })
            ->leftJoin('inventory.stock_entries as se', function ($join) {
                $join->on('se.company_id', '=', 'pl.company_id')
                    ->on('se.warehouse_id', '=', 'pl.warehouse_id');
            })
            ->select([
                'pl.company_id',
                'pl.warehouse_id',
                'pl.product_id',
                'pl.id as lot_id',
                'se.branch_id',
                'pl.lot_code',
                'pl.manufacture_at',
                'pl.expires_at',
                DB::raw('COALESCE(sl.stock, 0) as stock'),
                DB::raw('COALESCE(p.cost_price, 0) as unit_cost'),
            ])
            ->where('pl.status', 1);

        if ($companyId > 0) {
            $query->where('pl.company_id', $companyId);
        }
        if ($warehouseId) {
            $query->where('pl.warehouse_id', $warehouseId);
        }
        if ($productId) {
            $query->where('pl.product_id', $productId);
        }
        if ($lotId) {
            $query->where('pl.id', $lotId);
        }

        $rows = $query->groupBy([
            'pl.company_id', 'pl.warehouse_id', 'pl.product_id', 'pl.id', 'se.branch_id',
            'pl.lot_code', 'pl.manufacture_at', 'pl.expires_at', 'sl.stock', 'p.cost_price'
        ])->get();

        foreach ($rows as $row) {
            $daysToExpire = null;
            if ($row->expires_at) {
                $daysToExpire = (int) now()->startOfDay()->diffInDays((string) $row->expires_at, false);
            }

            $bucket = null;
            if ($daysToExpire !== null) {
                if ($daysToExpire < 0) {
                    $bucket = 'EXPIRED';
                } elseif ($daysToExpire <= 7) {
                    $bucket = 'DUE_7';
                } elseif ($daysToExpire <= 30) {
                    $bucket = 'DUE_30';
                } elseif ($daysToExpire <= 60) {
                    $bucket = 'DUE_60';
                } else {
                    $bucket = 'OK';
                }
            }

            DB::table('inventory.lot_expiry_projection')->insert([
                'company_id' => (int) $row->company_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
                'lot_id' => (int) $row->lot_id,
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'lot_code' => (string) $row->lot_code,
                'manufacture_at' => $row->manufacture_at,
                'expires_at' => $row->expires_at,
                'stock' => (float) $row->stock,
                'unit_cost' => (float) $row->unit_cost,
                'stock_value' => round((float) $row->stock * (float) $row->unit_cost, 8),
                'expiry_bucket' => $bucket,
                'days_to_expire' => $daysToExpire,
                'updated_at' => now(),
            ]);
        }
    }

    private static function applyInventoryMovement(array $payload): void
    {
        $companyId = (int) ($payload['company_id'] ?? 0);
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        $productId = (int) ($payload['product_id'] ?? 0);

        if ($companyId <= 0 || $warehouseId <= 0 || $productId <= 0) {
            throw new \RuntimeException('Missing required payload keys for projection');
        }

        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $lotId = isset($payload['lot_id']) && $payload['lot_id'] !== null ? (int) $payload['lot_id'] : null;
        $quantity = round((float) ($payload['quantity'] ?? 0), 8);
        $unitCost = (float) ($payload['unit_cost'] ?? 0);
        $lineTotal = round($quantity * $unitCost, 8);
        $movementType = strtoupper((string) ($payload['movement_type'] ?? 'IN'));
        $movedAt = isset($payload['moved_at']) && $payload['moved_at'] ? (string) $payload['moved_at'] : now()->toDateTimeString();
        $snapshotDate = substr($movedAt, 0, 10);

        $qtyIn = $movementType === 'IN' ? $quantity : 0;
        $qtyOut = $movementType === 'OUT' ? $quantity : 0;
        $qtyNet = $qtyIn - $qtyOut;

        $valueIn = $movementType === 'IN' ? $lineTotal : 0;
        $valueOut = $movementType === 'OUT' ? $lineTotal : 0;
        $valueNet = $valueIn - $valueOut;

        DB::statement(
            'INSERT INTO inventory.stock_daily_snapshot (
                snapshot_date, company_id, branch_id, warehouse_id, product_id, lot_id,
                qty_in, qty_out, qty_net,
                value_in, value_out, value_net,
                movement_count, first_moved_at, last_moved_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW())
            ON CONFLICT (snapshot_date, company_id, warehouse_id, product_id, lot_id)
            DO UPDATE SET
                qty_in = inventory.stock_daily_snapshot.qty_in + EXCLUDED.qty_in,
                qty_out = inventory.stock_daily_snapshot.qty_out + EXCLUDED.qty_out,
                qty_net = inventory.stock_daily_snapshot.qty_net + EXCLUDED.qty_net,
                value_in = inventory.stock_daily_snapshot.value_in + EXCLUDED.value_in,
                value_out = inventory.stock_daily_snapshot.value_out + EXCLUDED.value_out,
                value_net = inventory.stock_daily_snapshot.value_net + EXCLUDED.value_net,
                movement_count = inventory.stock_daily_snapshot.movement_count + 1,
                first_moved_at = LEAST(inventory.stock_daily_snapshot.first_moved_at, EXCLUDED.first_moved_at),
                last_moved_at = GREATEST(inventory.stock_daily_snapshot.last_moved_at, EXCLUDED.last_moved_at),
                branch_id = COALESCE(inventory.stock_daily_snapshot.branch_id, EXCLUDED.branch_id),
                updated_at = NOW()',
            [
                $snapshotDate,
                $companyId,
                $branchId,
                $warehouseId,
                $productId,
                $lotId,
                $qtyIn,
                $qtyOut,
                $qtyNet,
                $valueIn,
                $valueOut,
                $valueNet,
                $movedAt,
                $movedAt,
            ]
        );
    }
}
