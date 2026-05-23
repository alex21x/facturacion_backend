<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddMultitenantBaselineIndexes extends Migration
{
    public function up(): void
    {
        // Sales customers
        $this->createIndexIfColumns(
            'sales',
            'customers',
            'idx_customers_company_status_id',
            ['company_id', 'status', 'id DESC']
        );

        $this->createIndexIfColumns(
            'sales',
            'customers',
            'idx_customers_company_updated_at',
            ['company_id', 'updated_at DESC']
        );

        // Commercial documents listing and filtering
        $this->createIndexIfColumns(
            'sales',
            'commercial_documents',
            'idx_docs_company_branch_status_issue',
            ['company_id', 'branch_id', 'status', 'issue_at DESC']
        );

        $this->createIndexIfColumns(
            'sales',
            'commercial_documents',
            'idx_docs_company_kind_status_issue',
            ['company_id', 'document_kind', 'status', 'issue_at DESC']
        );

        // Suppliers catalog (purchases)
        $this->createIndexIfColumns(
            'inventory',
            'purchase_suppliers',
            'idx_purchase_suppliers_company_doc',
            ['company_id', 'doc_number']
        );

        // Cash operations
        $this->createIndexIfColumns(
            'sales',
            'cash_registers',
            'idx_cash_registers_company_branch_status',
            ['company_id', 'branch_id', 'status']
        );

        $this->createIndexIfColumns(
            'sales',
            'cash_sessions',
            'idx_cash_sessions_company_branch_status_created',
            ['company_id', 'branch_id', 'status', 'created_at DESC']
        );

        $this->createIndexIfColumns(
            'sales',
            'cash_movements',
            'idx_cash_movements_company_session_created',
            ['company_id', 'cash_session_id', 'created_at DESC']
        );

        // Inventory movements
        $this->createIndexIfColumns(
            'inventory',
            'stock_entries',
            'idx_stock_entries_company_branch_issue',
            ['company_id', 'branch_id', 'issue_at DESC']
        );

        $this->createIndexIfColumns(
            'inventory',
            'stock_entry_items',
            'idx_stock_entry_items_entry_product',
            ['entry_id', 'product_id']
        );

        // Sales detail lines
        $this->createIndexIfColumns(
            'sales',
            'commercial_document_items',
            'idx_document_items_document_product',
            ['document_id', 'product_id']
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('sales', 'idx_customers_company_status_id');
        $this->dropIndexIfExists('sales', 'idx_customers_company_updated_at');
        $this->dropIndexIfExists('sales', 'idx_docs_company_branch_status_issue');
        $this->dropIndexIfExists('sales', 'idx_docs_company_kind_status_issue');
        $this->dropIndexIfExists('inventory', 'idx_purchase_suppliers_company_doc');
        $this->dropIndexIfExists('sales', 'idx_cash_registers_company_branch_status');
        $this->dropIndexIfExists('sales', 'idx_cash_sessions_company_branch_status_created');
        $this->dropIndexIfExists('sales', 'idx_cash_movements_company_session_created');
        $this->dropIndexIfExists('inventory', 'idx_stock_entries_company_branch_issue');
        $this->dropIndexIfExists('inventory', 'idx_stock_entry_items_entry_product');
        $this->dropIndexIfExists('sales', 'idx_document_items_document_product');
    }

    private function createIndexIfColumns(string $schema, string $table, string $indexName, array $columns): void
    {
        if (!$this->tableExists($schema, $table)) {
            return;
        }

        foreach ($columns as $columnExpression) {
            $columnName = strtoupper(trim((string) preg_replace('/\s+(ASC|DESC)$/i', '', $columnExpression)));
            if (!$this->columnExists($schema, $table, $columnName)) {
                return;
            }
        }

        $tableRef = sprintf('%s.%s', $schema, $table);
        $columnList = implode(', ', $columns);

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
            $indexName,
            $tableRef,
            $columnList
        ));
    }

    private function dropIndexIfExists(string $schema, string $indexName): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.%s', $schema, $indexName));
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->whereRaw('UPPER(column_name) = ?', [$column])
            ->exists();
    }
}
