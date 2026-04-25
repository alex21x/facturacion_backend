<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales.commercial_documents')) {
            return;
        }

        DB::statement('ALTER TABLE sales.commercial_documents ADD COLUMN IF NOT EXISTS customer_vehicle_id BIGINT NULL');
        DB::statement('ALTER TABLE sales.commercial_documents ADD COLUMN IF NOT EXISTS vehicle_plate_snapshot VARCHAR(20) NULL');
        DB::statement('ALTER TABLE sales.commercial_documents ADD COLUMN IF NOT EXISTS vehicle_brand_snapshot VARCHAR(80) NULL');
        DB::statement('ALTER TABLE sales.commercial_documents ADD COLUMN IF NOT EXISTS vehicle_model_snapshot VARCHAR(80) NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS commercial_documents_customer_vehicle_idx ON sales.commercial_documents (company_id, customer_vehicle_id)');
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales.commercial_documents')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sales.commercial_documents_customer_vehicle_idx');
        DB::statement('ALTER TABLE sales.commercial_documents DROP COLUMN IF EXISTS vehicle_model_snapshot');
        DB::statement('ALTER TABLE sales.commercial_documents DROP COLUMN IF EXISTS vehicle_brand_snapshot');
        DB::statement('ALTER TABLE sales.commercial_documents DROP COLUMN IF EXISTS vehicle_plate_snapshot');
        DB::statement('ALTER TABLE sales.commercial_documents DROP COLUMN IF EXISTS customer_vehicle_id');
    }
};
