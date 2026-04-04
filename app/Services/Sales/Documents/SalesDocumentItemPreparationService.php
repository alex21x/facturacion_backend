<?php

namespace App\Services\Sales\Documents;

use App\Application\DTOs\Sales\DocumentTotalsDTO;
use Illuminate\Support\Facades\DB;

class SalesDocumentItemPreparationService
{
    public function __construct(
        private SalesDocumentSupportService $support,
        private SalesStockProjectionService $stockProjectionService
    ) {
    }

    public function prepareProcessedItems(
        int $companyId,
        ?int $warehouseId,
        array $items,
        bool $affectsStock,
        int $stockDirection,
        array $settings
    ): array {
        $productIds = collect($items)
            ->pluck('product_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $productMap = DB::table('inventory.products')
            ->select('id', 'name', 'unit_id', 'is_stockable', 'lot_tracking', 'status')
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds->all())
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $allLotIds = collect($items)
            ->pluck('lots')
            ->filter(fn ($lots) => is_array($lots) && !empty($lots))
            ->flatten(1)
            ->pluck('lot_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $lotMap = DB::table('inventory.product_lots')
            ->select('id', 'company_id', 'warehouse_id', 'product_id', 'status')
            ->where('company_id', $companyId)
            ->whereIn('id', $allLotIds->all())
            ->get()
            ->keyBy('id');

        $processedItems = [];

        foreach ($items as $index => $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
            $product = $productId ? $productMap->get($productId) : null;

            if ($productId !== null && !$product) {
                throw new SalesDocumentException('Product not found for line ' . ($index + 1));
            }

            if ($productId !== null && (int) $product->status !== 1) {
                throw new SalesDocumentException('Product inactive for line ' . ($index + 1));
            }

            $itemUnitId = isset($item['unit_id']) ? (int) $item['unit_id'] : null;
            if ($product && !$itemUnitId) {
                $itemUnitId = (int) $product->unit_id;
            }

            $conversion = $this->support->resolveLineConversion($companyId, $product, $item, $itemUnitId);
            $qtyBase = $conversion['qty_base'];
            $conversionFactor = $conversion['conversion_factor'];
            $baseUnitPrice = $conversion['base_unit_price'];
            $lotOutflowStrategy = strtoupper((string) ($settings['lot_outflow_strategy'] ?? 'MANUAL'));

            $itemLots = [];
            $lotBaseQtyTotal = 0.0;

            if (!empty($item['lots']) && is_array($item['lots'])) {
                foreach ($item['lots'] as $lot) {
                    $lotId = (int) $lot['lot_id'];
                    $lotQty = (float) $lot['qty'];
                    $lotBaseQty = $lotQty * $conversionFactor;
                    $lotRow = $lotMap->get($lotId);

                    if (!$lotRow) {
                        throw new SalesDocumentException('Lot not found for line ' . ($index + 1));
                    }

                    if ($product && (int) $lotRow->product_id !== (int) $product->id) {
                        throw new SalesDocumentException('Lot does not belong to product for line ' . ($index + 1));
                    }

                    if ($warehouseId !== null && (int) $lotRow->warehouse_id !== (int) $warehouseId) {
                        throw new SalesDocumentException('Lot does not belong to warehouse scope for line ' . ($index + 1));
                    }

                    $itemLots[] = [
                        'lot_id' => $lotId,
                        'qty' => $lotQty,
                        'qty_base' => $lotBaseQty,
                    ];

                    $lotBaseQtyTotal += $lotBaseQty;
                }

                if (abs($lotBaseQtyTotal - $qtyBase) > 0.0001) {
                    throw new SalesDocumentException('Lot quantity mismatch for line ' . ($index + 1));
                }
            }

            if (
                $affectsStock
                && $stockDirection < 0
                && $warehouseId !== null
                && $product
                && (bool) $product->is_stockable
                && (bool) $product->lot_tracking
                && (bool) ($settings['enable_inventory_pro'] ?? false)
                && (bool) ($settings['enable_lot_tracking'] ?? false)
                && empty($itemLots)
                && in_array($lotOutflowStrategy, ['FIFO', 'FEFO'], true)
            ) {
                $itemLots = $this->allocateOutboundLots(
                    $companyId,
                    (int) $warehouseId,
                    (int) $product->id,
                    (float) $qtyBase,
                    (float) $conversionFactor,
                    $lotOutflowStrategy,
                    (bool) $settings['allow_negative_stock'],
                    $index + 1
                );
            }

            if ($affectsStock && $product && (bool) $product->is_stockable) {
                if ($warehouseId === null) {
                    throw new SalesDocumentException('Warehouse is required for stockable product line ' . ($index + 1));
                }

                if (
                    $stockDirection < 0
                    && (bool) $product->lot_tracking
                    && empty($itemLots)
                    && (
                        !(bool) ($settings['enable_inventory_pro'] ?? false)
                        || !(bool) ($settings['enable_lot_tracking'] ?? false)
                        || $lotOutflowStrategy === 'MANUAL'
                        || (bool) $settings['enforce_lot_for_tracked']
                    )
                ) {
                    throw new SalesDocumentException('Lot is required for tracked product line ' . ($index + 1));
                }
            }

            $processedItems[] = [
                'raw' => $item,
                'product' => $product,
                'item_unit_id' => $itemUnitId,
                'qty_base' => $qtyBase,
                'conversion_factor' => $conversionFactor,
                'base_unit_price' => $baseUnitPrice,
                'lots' => $itemLots,
                'should_apply_stock' => $affectsStock && $product && (bool) $product->is_stockable && $stockDirection !== 0,
            ];
        }

        return $processedItems;
    }

    public function calculateDocumentTotals(array $items): DocumentTotalsDTO
    {
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $discountTotal = 0.0;
        $grandTotal = 0.0;

        foreach ($items as $item) {
            $itemSubtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : ((float) $item['qty'] * (float) $item['unit_price']);
            $itemTax = isset($item['tax_total']) ? (float) $item['tax_total'] : 0.0;
            $itemDiscount = isset($item['discount_total']) ? (float) $item['discount_total'] : 0.0;
            $itemTotal = isset($item['total']) ? (float) $item['total'] : ($itemSubtotal + $itemTax - $itemDiscount);

            $subtotal += $itemSubtotal;
            $taxTotal += $itemTax;
            $discountTotal += $itemDiscount;
            $grandTotal += $itemTotal;
        }

        return new DocumentTotalsDTO($subtotal, $taxTotal, $discountTotal, $grandTotal);
    }

    private function allocateOutboundLots(int $companyId, int $warehouseId, int $productId, float $qtyBase, float $conversionFactor, string $strategy, bool $allowNegativeStock, int $lineNumber): array
    {
        $candidateLots = DB::table('inventory.product_lots as pl')
            ->leftJoin('inventory.current_stock_by_lot as csl', function ($join) use ($companyId, $warehouseId, $productId) {
                $join->on('csl.lot_id', '=', 'pl.id')
                    ->where('csl.company_id', '=', $companyId)
                    ->where('csl.warehouse_id', '=', $warehouseId)
                    ->where('csl.product_id', '=', $productId);
            })
            ->select(['pl.id', 'pl.expires_at', 'pl.received_at', DB::raw('COALESCE(csl.stock, 0) as stock')])
            ->where('pl.company_id', $companyId)
            ->where('pl.warehouse_id', $warehouseId)
            ->where('pl.product_id', $productId)
            ->where('pl.status', 1)
            ->orderByRaw(
                $strategy === 'FEFO'
                    ? 'CASE WHEN pl.expires_at IS NULL THEN 1 ELSE 0 END, pl.expires_at ASC, pl.received_at ASC, pl.id ASC'
                    : 'pl.received_at ASC, pl.id ASC'
            )
            ->get();

        if ($candidateLots->isEmpty()) {
            throw new SalesDocumentException('No hay lotes disponibles para asignacion automatica en la linea ' . $lineNumber);
        }

        $remainingBase = round(max($qtyBase, 0), 8);
        $safeConversionFactor = max($conversionFactor, 0.00000001);
        $assignedLots = [];

        foreach ($candidateLots as $candidateLot) {
            if ($remainingBase <= 0.00000001) {
                break;
            }

            $availableBase = max(0, $this->stockProjectionService->projectedLotStock($companyId, $warehouseId, $productId, (int) $candidateLot->id));
            if ($availableBase <= 0.00000001) {
                continue;
            }

            $allocatedBase = min($availableBase, $remainingBase);
            $assignedLots[] = [
                'lot_id' => (int) $candidateLot->id,
                'qty' => round($allocatedBase / $safeConversionFactor, 8),
                'qty_base' => round($allocatedBase, 8),
            ];
            $remainingBase = round($remainingBase - $allocatedBase, 8);
        }

        if ($remainingBase > 0.00000001) {
            if (!$allowNegativeStock || empty($assignedLots)) {
                throw new SalesDocumentException('Stock insuficiente por lotes para asignacion automatica en la linea ' . $lineNumber);
            }

            $lastIndex = count($assignedLots) - 1;
            $assignedLots[$lastIndex]['qty_base'] = round($assignedLots[$lastIndex]['qty_base'] + $remainingBase, 8);
            $assignedLots[$lastIndex]['qty'] = round($assignedLots[$lastIndex]['qty_base'] / $safeConversionFactor, 8);
        }

        return $assignedLots;
    }
}
