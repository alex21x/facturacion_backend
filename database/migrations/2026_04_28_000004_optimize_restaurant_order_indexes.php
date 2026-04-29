<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class OptimizeRestaurantOrderIndexes extends Migration
{
    public function up(): void
    {
        // Guard for mixed environments where sales schema/tables may not exist yet.
        $documentsTableExists = DB::table('information_schema.tables')
            ->where('table_schema', 'sales')
            ->where('table_name', 'commercial_documents')
            ->exists();

        if ($documentsTableExists) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_sales_docs_restaurant_core
                 ON sales.commercial_documents (company_id, document_kind, branch_id, id DESC)'
            );

            DB::statement(
                "CREATE INDEX IF NOT EXISTS idx_sales_docs_restaurant_kitchen_status
                 ON sales.commercial_documents ((UPPER(COALESCE(metadata->>'restaurant_order_status', 'PENDING'))))"
            );
        }

        $itemsTableExists = DB::table('information_schema.tables')
            ->where('table_schema', 'sales')
            ->where('table_name', 'commercial_document_items')
            ->exists();

        if ($itemsTableExists) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_sales_doc_items_document_line
                 ON sales.commercial_document_items (document_id, line_no)'
            );

            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_sales_doc_items_document_qty
                 ON sales.commercial_document_items (document_id, qty)'
            );
        }

        $tablesTableExists = DB::table('information_schema.tables')
            ->where('table_schema', 'restaurant')
            ->where('table_name', 'tables')
            ->exists();

        if ($tablesTableExists) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_restaurant_tables_company_branch_status
                 ON restaurant.tables (company_id, branch_id, status, code)'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales.idx_sales_docs_restaurant_core');
        DB::statement('DROP INDEX IF EXISTS sales.idx_sales_docs_restaurant_kitchen_status');
        DB::statement('DROP INDEX IF EXISTS sales.idx_sales_doc_items_document_line');
        DB::statement('DROP INDEX IF EXISTS sales.idx_sales_doc_items_document_qty');
        DB::statement('DROP INDEX IF EXISTS restaurant.idx_restaurant_tables_company_branch_status');
    }
}
