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

        DB::statement('ALTER TABLE sales.customers ADD COLUMN IF NOT EXISTS tipo_cliente_codigo integer NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS customers_tipo_cliente_codigo_idx ON sales.customers (tipo_cliente_codigo)');
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales.customers')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sales.customers_tipo_cliente_codigo_idx');
        DB::statement('ALTER TABLE sales.customers DROP COLUMN IF EXISTS tipo_cliente_codigo');
    }
};
