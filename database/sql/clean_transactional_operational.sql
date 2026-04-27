-- Limpieza de datos operacionales/transaccionales para instalaciones limpias.
-- Preserva maestros/configuracion: auth.*, appcfg.*, core.*, master.*,
-- pero limpia clientes, vehiculos y catalogo de productos.

BEGIN;

-- CASCADE handles FK dependencies without needing to disable triggers.
-- Order still matters for clarity but CASCADE makes it safe regardless.
SET client_min_messages = warning;

DO $$
DECLARE
    table_name text;
    tables_to_clean text[] := ARRAY[
        -- Sales transactional
        'sales.commercial_document_item_lots',
        'sales.commercial_document_items',
        'sales.commercial_document_payments',
        'sales.daily_summary_items',
        'sales.sunat_exception_actions',
        'sales.tax_bridge_audit_logs',
        'sales.commercial_documents',
        'sales.daily_summaries',
        'sales.gre_guides',
        'sales.sales_order_item_lots',
        'sales.sales_order_items',
        'sales.sales_order_payments',
        'sales.sales_orders',
        'sales.cash_movements',
        'sales.cash_sessions',
        'sales.customer_vehicles',
        'sales.customer_price_profiles',
        'sales.customers',

        -- Inventory transactional
        'inventory.stock_transformation_lines',
        'inventory.stock_transformations',
        'inventory.stock_entry_items',
        'inventory.stock_entries',
        'inventory.inventory_ledger',
        'inventory.stock_daily_snapshot',
        'inventory.lot_expiry_projection',
        'inventory.product_lots',
        'inventory.outbox_events',
        'inventory.report_requests',
        'inventory.product_import_batch_items',
        'inventory.product_import_batches',

        -- Product catalog reset requested for clean install
        'inventory.product_sale_units',
        'inventory.products'
    ];
BEGIN
    FOREACH table_name IN ARRAY tables_to_clean LOOP
        IF to_regclass(table_name) IS NOT NULL THEN
            EXECUTE format('TRUNCATE TABLE %I.%I CASCADE',
                split_part(table_name, '.', 1),
                split_part(table_name, '.', 2));
        END IF;
    END LOOP;

    -- Reiniciar correlativos de comprobantes en 0.
    IF to_regclass('sales.series_numbers') IS NOT NULL THEN
        EXECUTE 'UPDATE sales.series_numbers SET current_number = 0';
    END IF;

    IF to_regclass('sales.document_sequences') IS NOT NULL THEN
        EXECUTE 'UPDATE sales.document_sequences SET current_number = 0';
    END IF;
END;
$$;

COMMIT;
