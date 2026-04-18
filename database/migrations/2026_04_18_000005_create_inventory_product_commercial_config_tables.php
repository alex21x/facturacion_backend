<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateInventoryProductCommercialConfigTables extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.product_sale_units (
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                unit_id BIGINT NOT NULL,
                is_base BOOLEAN NOT NULL DEFAULT FALSE,
                status SMALLINT NOT NULL DEFAULT 1,
                updated_by BIGINT NULL,
                updated_at TIMESTAMPTZ NULL,
                PRIMARY KEY (company_id, product_id, unit_id)
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.product_price_tier_values (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                price_tier_id BIGINT NOT NULL,
                unit_id BIGINT NULL,
                unit_price NUMERIC(18,6) NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                updated_by BIGINT NULL,
                updated_at TIMESTAMPTZ NULL,
                UNIQUE(company_id, product_id, price_tier_id, unit_id)
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.product_tier_prices (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                tier_id BIGINT NOT NULL,
                currency_id BIGINT NOT NULL,
                unit_price NUMERIC(14,4) NOT NULL,
                valid_from TIMESTAMPTZ NULL,
                valid_to TIMESTAMPTZ NULL,
                status SMALLINT NOT NULL DEFAULT 1
            )'
        );

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_product_sale_units_company_product ON inventory.product_sale_units (company_id, product_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sales_product_price_tier_values_company_product ON sales.product_price_tier_values (company_id, product_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sales_product_tier_prices_company_product_status ON sales.product_tier_prices (company_id, product_id, status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales.idx_sales_product_tier_prices_company_product_status');
        DB::statement('DROP INDEX IF EXISTS sales.idx_sales_product_price_tier_values_company_product');
        DB::statement('DROP INDEX IF EXISTS inventory.idx_inventory_product_sale_units_company_product');
        DB::statement('DROP TABLE IF EXISTS sales.product_tier_prices');
        DB::statement('DROP TABLE IF EXISTS sales.product_price_tier_values');
        DB::statement('DROP TABLE IF EXISTS inventory.product_sale_units');
    }
}