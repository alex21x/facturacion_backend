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

        if (!Schema::hasTable('inventory.products')) {
            return;
        }

        // Speed up duplicate checks used by POST /api/inventory/products.
        $this->createIndexIfMissing(
            'inventory_products_company_sku_upper_idx',
            "CREATE INDEX inventory_products_company_sku_upper_idx ON inventory.products (company_id, UPPER(COALESCE(sku, ''))) WHERE deleted_at IS NULL"
        );

        $this->createIndexIfMissing(
            'inventory_products_company_barcode_idx',
            "CREATE INDEX inventory_products_company_barcode_idx ON inventory.products (company_id, barcode) WHERE deleted_at IS NULL"
        );

        $this->createIndexIfMissing(
            'inventory_products_company_name_nature_unit_upper_idx',
            "CREATE INDEX inventory_products_company_name_nature_unit_upper_idx ON inventory.products (company_id, UPPER(TRIM(COALESCE(name, ''))), product_nature, unit_id) WHERE deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        if ((string) config('database.default') !== 'pgsql') {
            return;
        }

        if (!Schema::hasTable('inventory.products')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS inventory.inventory_products_company_name_nature_unit_upper_idx');
        DB::statement('DROP INDEX IF EXISTS inventory.inventory_products_company_barcode_idx');
        DB::statement('DROP INDEX IF EXISTS inventory.inventory_products_company_sku_upper_idx');
    }

    private function createIndexIfMissing(string $indexName, string $ddl): void
    {
        $exists = DB::table('pg_indexes')
            ->where('schemaname', 'inventory')
            ->where('indexname', $indexName)
            ->exists();

        if (!$exists) {
            DB::statement($ddl);
        }
    }
};
