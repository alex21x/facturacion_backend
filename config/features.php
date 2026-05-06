<?php

// Feature codes are now driven from appcfg.feature_labels (DB).
// This array is kept as emergency fallback only for environments
// where the migration has not run yet.
return [
    'commerce_feature_codes' => [
        'RESTAURANT_MENU_IGV_INCLUDED',
        'RESTAURANT_RECIPES_ENABLED',
        'PRODUCT_MULTI_UOM',
        'PRODUCT_UOM_CONVERSIONS',
        'PRODUCT_WHOLESALE_PRICING',
        'INVENTORY_PRODUCTS_BY_PROFILE',
        'INVENTORY_PRODUCT_MASTERS_BY_PROFILE',
        'SALES_CUSTOMER_PRICE_PROFILE',
        'SALES_WORKSHOP_MULTI_VEHICLE',
        'SALES_SELLER_TO_CASHIER',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
        'SALES_ANTICIPO_ENABLED',
        'SALES_TAX_BRIDGE',
        'SALES_TAX_BRIDGE_DEBUG_VIEW',
        'SALES_GLOBAL_DISCOUNT_ENABLED',
        'SALES_ITEM_DISCOUNT_ENABLED',
        'SALES_FREE_ITEMS_ENABLED',
        'SALES_VOID_REQUIRE_PASSWORD',
        'SALES_DETRACCION_ENABLED',
        'SALES_RETENCION_ENABLED',
        'SALES_PERCEPCION_ENABLED',
        'PURCHASES_GLOBAL_DISCOUNT_ENABLED',
        'PURCHASES_ITEM_DISCOUNT_ENABLED',
        'PURCHASES_FREE_ITEMS_ENABLED',
        'PURCHASES_DETRACCION_ENABLED',
        'PURCHASES_RETENCION_COMPRADOR_ENABLED',
        'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
        'PURCHASES_PERCEPCION_ENABLED',
    ],

    'feature_labels_es' => [
        'RESTAURANT_MENU_IGV_INCLUDED' => 'Precios restaurante con IGV incluido',
        'RESTAURANT_RECIPES_ENABLED' => 'Recetas de restaurante',
        'PRODUCT_MULTI_UOM' => 'Productos con multiples unidades',
        'PRODUCT_UOM_CONVERSIONS' => 'Conversiones de unidades de producto',
        'PRODUCT_WHOLESALE_PRICING' => 'Precios por mayor',
        'INVENTORY_PRODUCTS_BY_PROFILE' => 'Productos por perfil',
        'INVENTORY_PRODUCT_MASTERS_BY_PROFILE' => 'Maestros de productos por perfil',
        'SALES_CUSTOMER_PRICE_PROFILE' => 'Precios por cliente',
        'SALES_WORKSHOP_MULTI_VEHICLE' => 'Taller: clientes con multiples vehiculos',
        'SALES_SELLER_TO_CASHIER' => 'Flujo vendedor a caja',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' => 'Editar emitidos antes de respuesta final SUNAT',
        'SALES_ANTICIPO_ENABLED' => 'Cobro con anticipo',
        'SALES_TAX_BRIDGE' => 'Envio a SUNAT',
        'SALES_TAX_BRIDGE_DEBUG_VIEW' => 'Ver diagnostico SUNAT',
        'SALES_GLOBAL_DISCOUNT_ENABLED' => 'Descuento global en ventas',
        'SALES_ITEM_DISCOUNT_ENABLED' => 'Descuento por item en ventas',
        'SALES_FREE_ITEMS_ENABLED' => 'Operaciones gratuitas en ventas',
        'SALES_VOID_REQUIRE_PASSWORD' => 'Solicitar clave al anular',
        'SALES_DETRACCION_ENABLED' => 'Usar detraccion en ventas',
        'SALES_RETENCION_ENABLED' => 'Usar retencion en ventas',
        'SALES_PERCEPCION_ENABLED' => 'Usar percepcion en ventas',
        'PURCHASES_GLOBAL_DISCOUNT_ENABLED' => 'Descuento global en compras',
        'PURCHASES_ITEM_DISCOUNT_ENABLED' => 'Descuento por item en compras',
        'PURCHASES_FREE_ITEMS_ENABLED' => 'Operaciones gratuitas en compras',
        'PURCHASES_DETRACCION_ENABLED' => 'Usar detraccion en compras',
        'PURCHASES_RETENCION_COMPRADOR_ENABLED' => 'Retencion compra por comprador',
        'PURCHASES_RETENCION_PROVEEDOR_ENABLED' => 'Retencion compra por proveedor',
        'PURCHASES_PERCEPCION_ENABLED' => 'Usar percepcion en compras',
    ],

    // These feature codes govern structural business flows and may ONLY be
    // modified by platform-level superadmins (SUPERADMIN / SUPER_ADMIN role).
    // Regular company admins (ADMIN / ADMINISTRADOR) are rejected at the API
    // layer even if they attempt a direct call.  The POS frontend also hides
    // the toggle UI for these codes (adminManagedFeatureCodes list).
    'superadmin_only_feature_codes' => [
        'SALES_SELLER_TO_CASHIER',
        'SALES_DETRACCION_ENABLED',
        'SALES_PERCEPCION_ENABLED',
        'SALES_RETENCION_ENABLED',
        'SALES_ANTICIPO_ENABLED',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
        'PURCHASES_DETRACCION_ENABLED',
        'PURCHASES_PERCEPCION_ENABLED',
        'PURCHASES_RETENCION_COMPRADOR_ENABLED',
        'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
    ],
];
