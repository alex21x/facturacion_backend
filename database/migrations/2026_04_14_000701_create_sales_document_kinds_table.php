<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE TABLE IF NOT EXISTS sales.document_kinds (\n"
            . "  code VARCHAR(30) PRIMARY KEY,\n"
            . "  label VARCHAR(120) NOT NULL,\n"
            . "  sort_order INTEGER NOT NULL DEFAULT 0,\n"
            . "  is_enabled BOOLEAN NOT NULL DEFAULT TRUE,\n"
            . "  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n"
            . "  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()\n"
            . ")"
        );

        DB::statement(
            "INSERT INTO sales.document_kinds (code, label, sort_order, is_enabled)\n"
            . "VALUES\n"
            . "  ('QUOTATION', 'Cotizacion', 10, TRUE),\n"
            . "  ('SALES_ORDER', 'Pedido de Venta', 20, TRUE),\n"
            . "  ('INVOICE', 'Factura', 30, TRUE),\n"
            . "  ('RECEIPT', 'Boleta', 40, TRUE),\n"
            . "  ('CREDIT_NOTE', 'Nota de Credito', 50, TRUE),\n"
            . "  ('DEBIT_NOTE', 'Nota de Debito', 60, TRUE)\n"
            . "ON CONFLICT (code) DO UPDATE SET\n"
            . "  label = EXCLUDED.label,\n"
            . "  sort_order = EXCLUDED.sort_order,\n"
            . "  updated_at = NOW()"
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sales.document_kinds');
    }
};
