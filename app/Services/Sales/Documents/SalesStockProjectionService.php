<?php

namespace App\Services\Sales\Documents;

use Illuminate\Support\Facades\DB;

class SalesStockProjectionService
{
    private array $stockProjection = [];
    private array $lotStockProjection = [];

    public function reset(): void
    {
        $this->stockProjection = [];
        $this->lotStockProjection = [];
    }

    public function applyCurrentStockDelta(int $companyId, int $warehouseId, int $productId, float $delta, bool $allowNegativeStock): void
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;

        if (!array_key_exists($projectionKey, $this->stockProjection)) {
            $row = DB::table('inventory.current_stock')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();

            $this->stockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        $next = $this->stockProjection[$projectionKey] + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for product #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }

    public function projectedLotStock(int $companyId, int $warehouseId, int $productId, int $lotId): float
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;

        if (!array_key_exists($projectionKey, $this->lotStockProjection)) {
            $row = DB::table('inventory.current_stock_by_lot')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('lot_id', $lotId)
                ->first();

            $this->lotStockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        return (float) $this->lotStockProjection[$projectionKey];
    }

    public function applyLotStockDelta(int $companyId, int $warehouseId, int $productId, int $lotId, float $delta, bool $allowNegativeStock): void
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;
        $next = $this->projectedLotStock($companyId, $warehouseId, $productId, $lotId) + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for lot #' . $lotId);
        }

        $this->lotStockProjection[$projectionKey] = round($next, 8);
    }
}
