<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.feature_labels (
                feature_code VARCHAR(120) PRIMARY KEY,
                label_es VARCHAR(220) NOT NULL,
                description TEXT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );

        DB::statement("INSERT INTO appcfg.feature_labels (feature_code, label_es, description, status)
            VALUES
                ('RESTAURANT_MENU_IGV_INCLUDED', 'Restaurante: precio de carta incluye IGV', NULL, 1),
                ('PRODUCT_MULTI_UOM', 'Unidades multiples por producto', NULL, 1),
                ('PRODUCT_UOM_CONVERSIONS', 'Conversion entre unidades de producto', NULL, 1),
                ('PRODUCT_WHOLESALE_PRICING', 'Precios mayoristas por volumen', NULL, 1),
                ('INVENTORY_PRODUCTS_BY_PROFILE', 'Inventario: productos por perfil', NULL, 1),
                ('INVENTORY_PRODUCT_MASTERS_BY_PROFILE', 'Inventario: maestros por perfil', NULL, 1),
                ('SALES_CUSTOMER_PRICE_PROFILE', 'Ventas: precios por cliente', NULL, 1),
                ('SALES_SELLER_TO_CASHIER', 'Flujo vendedor a caja independiente', NULL, 1),
                ('SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL', 'Ventas: editar emitidos antes de estado SUNAT final', NULL, 1),
                ('SALES_ANTICIPO_ENABLED', 'Ventas: permitir anticipos', NULL, 1),
                ('SALES_TAX_BRIDGE', 'Ventas: puente tributario SUNAT', NULL, 1),
                ('SALES_DETRACCION_ENABLED', 'Ventas: detraccion habilitada', NULL, 1),
                ('SALES_RETENCION_ENABLED', 'Ventas: retencion habilitada', NULL, 1),
                ('SALES_PERCEPCION_ENABLED', 'Ventas: percepcion habilitada', NULL, 1),
                ('PURCHASES_DETRACCION_ENABLED', 'Compras: detraccion habilitada', NULL, 1),
                ('PURCHASES_RETENCION_COMPRADOR_ENABLED', 'Compras: retencion comprador habilitada', NULL, 1),
                ('PURCHASES_RETENCION_PROVEEDOR_ENABLED', 'Compras: retencion proveedor habilitada', NULL, 1),
                ('PURCHASES_PERCEPCION_ENABLED', 'Compras: percepcion habilitada', NULL, 1)
            ON CONFLICT (feature_code) DO UPDATE SET
                label_es = EXCLUDED.label_es,
                description = EXCLUDED.description,
                status = EXCLUDED.status,
                updated_at = NOW()"
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS appcfg.feature_labels');
    }
};
