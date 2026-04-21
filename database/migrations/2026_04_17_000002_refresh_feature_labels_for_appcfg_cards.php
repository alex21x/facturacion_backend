<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("INSERT INTO appcfg.feature_labels (feature_code, label_es, description, status)
            VALUES
                ('DOC_KIND_CREDIT_NOTE', 'Notas de crédito', NULL, 1),
                ('DOC_KIND_CREDIT_NOTE_', 'Notas de crédito', NULL, 1),
                ('DOC_KIND_DEBIT_NOTE', 'Notas de débito', NULL, 1),
                ('DOC_KIND_DEBIT_NOTE_', 'Notas de débito', NULL, 1),
                ('RESTAURANT_MENU_IGV_INCLUDED', 'Menú con IGV incluido', NULL, 1),
                ('PRODUCT_MULTI_UOM', 'Múltiples unidades por producto', NULL, 1),
                ('PRODUCT_UOM_CONVERSIONS', 'Conversión de unidades', NULL, 1),
                ('PRODUCT_WHOLESALE_PRICING', 'Precios por volumen', NULL, 1),
                ('INVENTORY_PRODUCTS_BY_PROFILE', 'Productos según perfil', NULL, 1),
                ('INVENTORY_PRODUCT_MASTERS_BY_PROFILE', 'Catálogo según perfil', NULL, 1),
                ('SALES_CUSTOMER_PRICE_PROFILE', 'Precios por cliente', NULL, 1),
                ('SALES_SELLER_TO_CASHIER', 'Flujo vendedor a caja', NULL, 1),
                ('SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL', 'Editar emitidos antes de respuesta final SUNAT', NULL, 1),
                ('SALES_ANTICIPO_ENABLED', 'Cobro con anticipo', NULL, 1),
                ('SALES_TAX_BRIDGE', 'Envío a SUNAT', NULL, 1),
                ('SALES_TAX_BRIDGE_DEBUG_VIEW', 'Ver diagnóstico SUNAT', NULL, 1),
                ('SALES_DETRACCION_ENABLED', 'Usar detracción en ventas', NULL, 1),
                ('SALES_RETENCION_ENABLED', 'Usar retención en ventas', NULL, 1),
                ('SALES_PERCEPCION_ENABLED', 'Usar percepción en ventas', NULL, 1),
                ('PURCHASES_DETRACCION_ENABLED', 'Usar detracción en compras', NULL, 1),
                ('PURCHASES_RETENCION_COMPRADOR_ENABLED', 'Retención compra por comprador', NULL, 1),
                ('PURCHASES_RETENCION_PROVEEDOR_ENABLED', 'Retención compra por proveedor', NULL, 1),
                ('PURCHASES_PERCEPCION_ENABLED', 'Usar percepción en compras', NULL, 1)
            ON CONFLICT (feature_code) DO UPDATE SET
                label_es = EXCLUDED.label_es,
                description = EXCLUDED.description,
                status = EXCLUDED.status,
                updated_at = NOW()"
        );
    }

    public function down(): void
    {
        DB::statement("UPDATE appcfg.feature_labels SET
                label_es = CASE feature_code
                    WHEN 'RESTAURANT_MENU_IGV_INCLUDED' THEN 'Restaurante: precio de carta incluye IGV'
                    WHEN 'PRODUCT_MULTI_UOM' THEN 'Unidades multiples por producto'
                    WHEN 'PRODUCT_UOM_CONVERSIONS' THEN 'Conversion entre unidades de producto'
                    WHEN 'PRODUCT_WHOLESALE_PRICING' THEN 'Precios mayoristas por volumen'
                    WHEN 'INVENTORY_PRODUCTS_BY_PROFILE' THEN 'Inventario: productos por perfil'
                    WHEN 'INVENTORY_PRODUCT_MASTERS_BY_PROFILE' THEN 'Inventario: maestros por perfil'
                    WHEN 'SALES_CUSTOMER_PRICE_PROFILE' THEN 'Ventas: precios por cliente'
                    WHEN 'SALES_SELLER_TO_CASHIER' THEN 'Flujo vendedor a caja independiente'
                    WHEN 'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' THEN 'Ventas: editar emitidos antes de estado SUNAT final'
                    WHEN 'SALES_ANTICIPO_ENABLED' THEN 'Ventas: permitir anticipos'
                    WHEN 'SALES_TAX_BRIDGE' THEN 'Ventas: puente tributario SUNAT'
                    WHEN 'SALES_TAX_BRIDGE_DEBUG_VIEW' THEN 'Ventas: visor tecnico de bridge SUNAT'
                    WHEN 'SALES_DETRACCION_ENABLED' THEN 'Ventas: detraccion habilitada'
                    WHEN 'SALES_RETENCION_ENABLED' THEN 'Ventas: retencion habilitada'
                    WHEN 'SALES_PERCEPCION_ENABLED' THEN 'Ventas: percepcion habilitada'
                    WHEN 'PURCHASES_DETRACCION_ENABLED' THEN 'Compras: detraccion habilitada'
                    WHEN 'PURCHASES_RETENCION_COMPRADOR_ENABLED' THEN 'Compras: retencion comprador habilitada'
                    WHEN 'PURCHASES_RETENCION_PROVEEDOR_ENABLED' THEN 'Compras: retencion proveedor habilitada'
                    WHEN 'PURCHASES_PERCEPCION_ENABLED' THEN 'Compras: percepcion habilitada'
                    ELSE label_es
                END,
                updated_at = NOW()
            WHERE feature_code IN (
                'DOC_KIND_CREDIT_NOTE',
                'DOC_KIND_CREDIT_NOTE_',
                'DOC_KIND_DEBIT_NOTE',
                'DOC_KIND_DEBIT_NOTE_',
                'RESTAURANT_MENU_IGV_INCLUDED',
                'PRODUCT_MULTI_UOM',
                'PRODUCT_UOM_CONVERSIONS',
                'PRODUCT_WHOLESALE_PRICING',
                'INVENTORY_PRODUCTS_BY_PROFILE',
                'INVENTORY_PRODUCT_MASTERS_BY_PROFILE',
                'SALES_CUSTOMER_PRICE_PROFILE',
                'SALES_SELLER_TO_CASHIER',
                'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
                'SALES_ANTICIPO_ENABLED',
                'SALES_TAX_BRIDGE',
                'SALES_TAX_BRIDGE_DEBUG_VIEW',
                'SALES_DETRACCION_ENABLED',
                'SALES_RETENCION_ENABLED',
                'SALES_PERCEPCION_ENABLED',
                'PURCHASES_DETRACCION_ENABLED',
                'PURCHASES_RETENCION_COMPRADOR_ENABLED',
                'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
                'PURCHASES_PERCEPCION_ENABLED'
            )");
    }
};