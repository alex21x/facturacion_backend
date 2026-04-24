-- Limpieza de datos operacionales/transaccionales para instalaciones limpias.
-- Preserva maestros/configuracion: auth.*, appcfg.*, core.*, master.*,
-- inventory.products*, sales.series_numbers, sales.document_sequences, etc.

BEGIN;

SET session_replication_role = replica;

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
        'inventory.product_import_batches'
    ];
BEGIN
    FOREACH table_name IN ARRAY tables_to_clean LOOP
        IF to_regclass(table_name) IS NOT NULL THEN
            EXECUTE format('TRUNCATE TABLE %s', table_name);
        END IF;
    END LOOP;
END;
$$;

SET session_replication_role = default;

COMMIT;
