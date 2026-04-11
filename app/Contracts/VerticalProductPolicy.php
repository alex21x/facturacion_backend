<?php

namespace App\Contracts;

/**
 * Contract for vertical-specific product catalogue behaviour.
 *
 * Products are shared across all verticals (SKU, price, tax).
 * Each vertical may need its own extended attributes layer on top.
 *
 * VERTICAL EXAMPLES
 * ─────────────────
 *   Restaurant → Menu items with recipe (ingredients + quantities).
 *                Modifiers/add-ons (extra cheese, sin cebolla).
 *   Pharmacy   → Active ingredient, presentation (tableta/jarabe), controlled flag,
 *                DIGEMID registration, prescription required.
 *   Workshop   → Services (labour) vs. parts; labour has no stock.
 *   Hotel      → Room types: capacity, bed config, rate plan.
 *   Hardware   → Variants: dimension, colour, material.
 *
 * ADDING A NEW VERTICAL
 * ─────────────────────
 *   class RestaurantProductPolicy implements VerticalProductPolicy { ... }
 *   class PharmacyProductPolicy    implements VerticalProductPolicy { ... }
 */
interface VerticalProductPolicy
{
    /**
     * Validate vertical-specific product fields before saving.
     * e.g. require recipe when product_type=MENU_ITEM for restaurant.
     *
     * @throws \RuntimeException
     */
    public function validateProductInput(array $input, object $authUser, int $companyId): void;

    /**
     * Return vertical-specific extended attributes to store alongside the base product.
     * The nucleus stores these in a vertical-scoped metadata column or extension table.
     *
     * @return array  Key-value pairs for vertical metadata
     */
    public function buildExtendedAttributes(array $input, int $companyId): array;

    /**
     * Post-save hook — e.g. persist recipe ingredients for restaurant,
     * register DIGEMID code for pharmacy.
     *
     * @param  array  $product   Saved product row
     * @param  int    $companyId
     */
    public function onProductSaved(array $product, int $companyId): void;
}
