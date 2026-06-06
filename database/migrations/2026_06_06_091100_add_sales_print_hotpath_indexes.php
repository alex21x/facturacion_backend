<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if ((string) config('database.default') !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('sales.commercial_document_payments')) {
            // Supports print/show query: WHERE document_id = ? ORDER BY id ASC and payment method join.
            $this->createIndexIfMissing(
                'sales',
                'idx_commercial_document_payments_doc_id',
                'CREATE INDEX idx_commercial_document_payments_doc_id ON sales.commercial_document_payments (document_id, id)'
            );

            $this->createIndexIfMissing(
                'sales',
                'idx_commercial_document_payments_doc_method',
                'CREATE INDEX idx_commercial_document_payments_doc_method ON sales.commercial_document_payments (document_id, payment_method_id)'
            );
        }

        if (Schema::hasTable('sales.commercial_document_item_lots')) {
            // Supports getLotsGroupedByItemIds where document_item_id IN (...)
            $this->createIndexIfMissing(
                'sales',
                'idx_commercial_document_item_lots_doc_item_id',
                'CREATE INDEX idx_commercial_document_item_lots_doc_item_id ON sales.commercial_document_item_lots (document_item_id)'
            );
        }
    }

    public function down(): void
    {
        if ((string) config('database.default') !== 'pgsql') {
            return;
        }

        $this->dropIndexIfExists('sales', 'idx_commercial_document_item_lots_doc_item_id');
        $this->dropIndexIfExists('sales', 'idx_commercial_document_payments_doc_method');
        $this->dropIndexIfExists('sales', 'idx_commercial_document_payments_doc_id');
    }

    private function createIndexIfMissing(string $schema, string $indexName, string $ddl): void
    {
        $exists = DB::table('pg_indexes')
            ->where('schemaname', $schema)
            ->where('indexname', $indexName)
            ->exists();

        if (!$exists) {
            DB::statement($ddl);
        }
    }

    private function dropIndexIfExists(string $schema, string $indexName): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.%s', $schema, $indexName));
    }
};
