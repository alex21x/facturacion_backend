<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateRestaurantRecipeTables extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS restaurant');

        DB::statement(
            'CREATE TABLE IF NOT EXISTS restaurant.product_recipes (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                menu_product_id BIGINT NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                notes VARCHAR(300) NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (company_id, menu_product_id)
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS restaurant.product_recipe_items (
                id BIGSERIAL PRIMARY KEY,
                recipe_id BIGINT NOT NULL,
                ingredient_product_id BIGINT NOT NULL,
                qty_required_base NUMERIC(18,8) NOT NULL,
                wastage_percent NUMERIC(8,4) NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (recipe_id, ingredient_product_id),
                CONSTRAINT fk_restaurant_recipe_items_recipe
                    FOREIGN KEY (recipe_id) REFERENCES restaurant.product_recipes(id) ON DELETE CASCADE
            )'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_restaurant_product_recipes_company_menu
             ON restaurant.product_recipes (company_id, menu_product_id)'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_restaurant_recipe_items_recipe_sort
             ON restaurant.product_recipe_items (recipe_id, sort_order)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS restaurant.idx_restaurant_recipe_items_recipe_sort');
        DB::statement('DROP INDEX IF EXISTS restaurant.idx_restaurant_product_recipes_company_menu');
        DB::statement('DROP TABLE IF EXISTS restaurant.product_recipe_items');
        DB::statement('DROP TABLE IF EXISTS restaurant.product_recipes');
    }
}
