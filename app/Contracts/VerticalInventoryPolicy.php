<?php

namespace App\Contracts;

/**
 * Contract for vertical-specific inventory behaviour.
 *
 * Allows each vertical to plug custom logic into stock movements without
 * touching the shared inventory nucleus.
 *
 * VERTICAL EXAMPLES
 * ─────────────────
 *   Restaurant  → Recipe depletion: selling a "Lomo Saltado" decrements beef,
 *                 tomatoes, potatoes from stock automatically.
 *   Pharmacy    → Lot/batch tracking: stock is decremented per lot, expiry date
 *                 checked before sale (FEFO — First Expired, First Out).
 *   Workshop    → Parts consumed by work order: a repair job auto-decrements
 *                 the spare parts listed in the work order.
 *   Hotel       → Rooms are not physical stock; vertical skips decrement hooks.
 *
 * ADDING A NEW VERTICAL
 * ─────────────────────
 *   class RestaurantInventoryPolicy implements VerticalInventoryPolicy { ... }
 *   class PharmacyInventoryPolicy   implements VerticalInventoryPolicy { ... }
 *   class WorkshopInventoryPolicy   implements VerticalInventoryPolicy { ... }
 */
interface VerticalInventoryPolicy
{
    /**
     * Called BEFORE stock is decremented for a transaction line.
     * Throw to block the movement (e.g. expired lot for pharmacy, negative stock check).
     *
     * @param  int    $productId   Product being moved
     * @param  float  $quantity    Units to decrement
     * @param  int    $warehouseId Warehouse/almacén
     * @param  array  $context     Extra data: ['document_id', 'line_meta', 'lot_id', ...]
     * @throws \RuntimeException
     */
    public function onBeforeStockDecrement(int $productId, float $quantity, int $warehouseId, array $context = []): void;

    /**
     * Called AFTER stock movement is committed.
     * Use for recipe depletion, lot consumption records, work-order parts linking, etc.
     *
     * @param  int    $productId
     * @param  float  $quantity
     * @param  int    $warehouseId
     * @param  array  $context
     */
    public function onAfterStockDecrement(int $productId, float $quantity, int $warehouseId, array $context = []): void;

    /**
     * Called BEFORE stock is incremented (purchase or adjustment).
     * Use to validate lot uniqueness, check maximum capacity, etc.
     */
    public function onBeforeStockIncrement(int $productId, float $quantity, int $warehouseId, array $context = []): void;

    /**
     * Called AFTER stock is incremented.
     * Use to register new lots, link to purchase order, refresh recipe availability.
     */
    public function onAfterStockIncrement(int $productId, float $quantity, int $warehouseId, array $context = []): void;
}
