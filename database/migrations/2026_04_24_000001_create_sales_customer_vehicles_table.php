<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales.customers')) {
            return;
        }

        DB::statement('
            CREATE TABLE IF NOT EXISTS sales.customer_vehicles (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                customer_id BIGINT NOT NULL,
                plate VARCHAR(20) NOT NULL,
                plate_normalized VARCHAR(20) NOT NULL,
                brand VARCHAR(80) NULL,
                model VARCHAR(80) NULL,
                year SMALLINT NULL,
                color VARCHAR(40) NULL,
                vin VARCHAR(50) NULL,
                is_default BOOLEAN NOT NULL DEFAULT FALSE,
                status SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS customer_vehicles_company_customer_idx ON sales.customer_vehicles (company_id, customer_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS customer_vehicles_company_plate_unique_idx ON sales.customer_vehicles (company_id, plate_normalized) WHERE status = 1');
        DB::statement('CREATE INDEX IF NOT EXISTS customer_vehicles_search_idx ON sales.customer_vehicles (company_id, plate, brand, model)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sales.customer_vehicles');
    }
};
