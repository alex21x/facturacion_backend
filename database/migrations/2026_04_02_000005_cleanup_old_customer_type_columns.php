<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop tipo_cliente_codigo column from sales.customers (superseded by customer_type_id)
        DB::statement('ALTER TABLE sales.customers DROP COLUMN IF EXISTS tipo_cliente_codigo');
        DB::statement('DROP INDEX IF EXISTS sales.customers_tipo_cliente_codigo_idx');

        // Drop legacy core.tipo_clientes table (superseded by sales.customer_types)
        DB::statement('DROP TABLE IF EXISTS core.tipo_clientes');
    }

    public function down(): void
    {
        // Recreate core.tipo_clientes with minimal structure
        DB::statement(
            "CREATE TABLE IF NOT EXISTS core.tipo_clientes (
                id bigserial PRIMARY KEY,
                tipo_cliente varchar(120),
                codigo integer,
                abr_standar varchar(120),
                activo boolean DEFAULT true
            )"
        );

        // Restore tipo_cliente_codigo column
        DB::statement('ALTER TABLE sales.customers ADD COLUMN IF NOT EXISTS tipo_cliente_codigo integer NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS customers_tipo_cliente_codigo_idx ON sales.customers (tipo_cliente_codigo)');
    }
};
