<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OptimizeInventoryProductAutocompleteIndexes extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory.products')) {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable $exception) {
            // Continue with btree indexes if the current database user cannot install extensions.
        }

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_products_company_status_active_name ON inventory.products (company_id, status, name) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_products_company_active_sku ON inventory.products (company_id, sku) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_products_company_active_barcode ON inventory.products (company_id, barcode) WHERE deleted_at IS NULL');

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_products_name_trgm_active ON inventory.products USING gin (lower(name) gin_trgm_ops) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_products_sku_trgm_active ON inventory.products USING gin (lower(sku) gin_trgm_ops) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_products_barcode_trgm_active ON inventory.products USING gin (lower(barcode) gin_trgm_ops) WHERE deleted_at IS NULL');
        } catch (\Throwable $exception) {
            // Skip trigram indexes if pg_trgm is unavailable; the btree indexes above still help exact/prefix lookups.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory.products')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS inventory.idx_inventory_products_barcode_trgm_active');
        DB::statement('DROP INDEX IF EXISTS inventory.idx_inventory_products_sku_trgm_active');
        DB::statement('DROP INDEX IF EXISTS inventory.idx_inventory_products_name_trgm_active');
        DB::statement('DROP INDEX IF EXISTS inventory.idx_inventory_products_company_active_barcode');
        DB::statement('DROP INDEX IF EXISTS inventory.idx_inventory_products_company_active_sku');
        DB::statement('DROP INDEX IF EXISTS inventory.idx_inventory_products_company_status_active_name');
    }
}