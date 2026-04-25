<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration to clean transactional/operational data on fresh install.
     * IMPORTANT: This migration is designed to run ONLY on fresh installations
     * after the bootstrap SQL dump is restored. It cleans operational data while
     * preserving master data, configuration, series, and users.
     *
     * Tables PRESERVED (master/config — never touched):
     * - auth.*          (users, roles, permissions)
     * - appcfg.*        (application configuration)
     * - core.*          (companies, branches, settings)
     * - master.*        (catalogs: tax codes, UOM, etc.)
     * - inventory.products, inventory.categories, inventory.warehouses,
     *   inventory.product_* (full product catalog)
     * - sales.series_numbers, sales.document_sequences (series config)
     * - sales.cash_registers, sales.customers, sales.price_tiers,
     *   sales.document_kinds, sales.customer_types (master/config)
     *
     * Tables CLEANED (transactional operational data):
     * - sales.commercial_documents + related detail tables
     * - sales.sales_orders + related detail tables
     * - sales.cash_sessions, sales.cash_movements
     * - sales.daily_summaries + items
     * - sales.gre_guides
     * - sales.sunat_exception_actions, sales.tax_bridge_audit_logs
     * - inventory.inventory_ledger, inventory.stock_entries + items
     * - inventory.stock_transformations + lines
     * - inventory.product_lots, inventory.lot_expiry_projection
     * - inventory.stock_daily_snapshot
     * - inventory.outbox_events, inventory.report_requests
     * - inventory.product_import_batches + items
     */
    public function up(): void
    {
        if (!$this->shouldCleanTransactionalData()) {
            return;
        }

        DB::connection('pgsql')->statement('SET session_replication_role = replica');

        try {
            $this->cleanSalesTransactionalData();
            $this->cleanInventoryTransactionalData();
        } finally {
            DB::connection('pgsql')->statement('SET session_replication_role = default');
        }
    }

    public function down(): void
    {
        // This migration should not be rolled back in production
        // It's only for fresh installs
    }

    private function shouldCleanTransactionalData(): bool
    {
        // Require explicit opt-in flag during clean install bootstrap.
        // This avoids wiping operational data during regular updates.
        return env('APP_ENV') === 'local'
            && filter_var(env('ALLOW_CLEAN_TRANSACTIONAL_ON_INSTALL', false), FILTER_VALIDATE_BOOL);
    }

    private function tableExists(string $schemaTable): bool
    {
        [$schema, $table] = explode('.', $schemaTable, 2);
        $result = DB::selectOne(
            'SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?) AS exists',
            [$schema, $table]
        );

        return (bool) ($result->exists ?? false);
    }

    private function cleanSalesTransactionalData(): void
    {
        // Detail tables first (reference header tables via FK)
        $tables = [
            // Commercial documents (invoices, receipts, credit/debit notes, quotations)
            'sales.commercial_document_item_lots',
            'sales.commercial_document_items',
            'sales.commercial_document_payments',
            'sales.daily_summary_items',
            'sales.sunat_exception_actions',
            'sales.tax_bridge_audit_logs',
            'sales.commercial_documents',
            // Daily summaries (boletas/facturas sent to SUNAT)
            'sales.daily_summaries',
            // GRE guides (guías de remisión)
            'sales.gre_guides',
            // Sales orders (pedidos) — transactional, not configuration
            'sales.sales_order_item_lots',
            'sales.sales_order_items',
            'sales.sales_order_payments',
            'sales.sales_orders',
            // Cash sessions and movements
            'sales.cash_movements',
            'sales.cash_sessions',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("TRUNCATE TABLE $table");
            }
        }
    }

    private function cleanInventoryTransactionalData(): void
    {
        $tables = [
            // Stock movements and ledger
            'inventory.stock_transformation_lines',
            'inventory.stock_transformations',
            'inventory.stock_entry_items',
            'inventory.stock_entries',
            'inventory.inventory_ledger',
            // Computed / derived tables (rebuilt from ledger)
            'inventory.stock_daily_snapshot',
            'inventory.lot_expiry_projection',
            // Product lots (transactional lot records)
            'inventory.product_lots',
            // Event queues and async tasks
            'inventory.outbox_events',
            'inventory.report_requests',
            // Import history
            'inventory.product_import_batch_items',
            'inventory.product_import_batches',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("TRUNCATE TABLE $table");
            }
        }
    }
};
