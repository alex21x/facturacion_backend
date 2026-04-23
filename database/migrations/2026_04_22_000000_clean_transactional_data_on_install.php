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
     * preserving master data, configuration, series, and endpoints.
     * 
     * Tables preserved:
     * - master.* (master data: products, customers, suppliers, etc)
     * - appcfg.* (application configuration)
     * - core.series_numbers (document series)
     * - core.document_sequences (document numerators)
     * - core.endpoints/inpoints (API endpoints)
     * 
     * Tables cleaned:
     * - sales.* (operational sales data)
     * - purchases.* (operational purchase data)
     * - inventory.* (inventory movements, transfers)
     * - cash.* (cash transactions, flows)
     */
    public function up(): void
    {
        // Only run if this is a bootstrap operation (check if a specific marker exists)
        // This prevents accidental deletion on production environments
        if (!$this->shouldCleanTransactionalData()) {
            return;
        }

        DB::connection('pgsql')->statement('SET session_replication_role = replica');

        try {
            // Clean operational sales data
            $this->cleanSalesData();

            // Clean operational purchase data
            $this->cleanPurchasesData();

            // Clean inventory movements and transfers
            $this->cleanInventoryData();

            // Clean cash/financial transactions
            $this->cleanCashData();
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

    private function cleanSalesData(): void
    {
        $tables = [
            'sales.invoice_details',
            'sales.invoices',
            'sales.receipt_details',
            'sales.receipts',
            'sales.quotation_details',
            'sales.quotations',
            'sales.sales_order_details',
            'sales.sales_orders',
            'sales.credit_note_details',
            'sales.credit_notes',
            'sales.debit_note_details',
            'sales.debit_notes',
            'sales.gre_guide_details',
            'sales.gre_guides',
            'sales.invoice_payments',
            'sales.receipt_payments',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("TRUNCATE TABLE $table CASCADE");
            }
        }
    }

    private function cleanPurchasesData(): void
    {
        $tables = [
            'purchases.purchase_order_details',
            'purchases.purchase_orders',
            'purchases.purchase_invoice_details',
            'purchases.purchase_invoices',
            'purchases.purchase_receipt_details',
            'purchases.purchase_receipts',
            'purchases.purchase_payments',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("TRUNCATE TABLE $table CASCADE");
            }
        }
    }

    private function cleanInventoryData(): void
    {
        $tables = [
            'inventory.movement_details',
            'inventory.movements',
            'inventory.transfer_details',
            'inventory.transfers',
            'inventory.stock_adjustments',
            'inventory.stock_counts',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("TRUNCATE TABLE $table CASCADE");
            }
        }
    }

    private function cleanCashData(): void
    {
        $tables = [
            'cash.cash_flows',
            'cash.cash_transactions',
            'cash.cash_count_details',
            'cash.cash_counts',
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("TRUNCATE TABLE $table CASCADE");
            }
        }
    }
};
