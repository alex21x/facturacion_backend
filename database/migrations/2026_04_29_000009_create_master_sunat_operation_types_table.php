<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS master.sunat_operation_types (id BIGSERIAL PRIMARY KEY, code VARCHAR(10) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, regime VARCHAR(20) NOT NULL DEFAULT 'NONE', sort_order INTEGER NOT NULL DEFAULT 0, is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");

        $defaults = [
            ['code' => '0101', 'name' => 'Venta interna', 'regime' => 'NONE', 'sort_order' => 10],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION', 'sort_order' => 20],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION', 'sort_order' => 30],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION', 'sort_order' => 40],
        ];

        foreach ($defaults as $row) {
            DB::statement(
                'INSERT INTO master.sunat_operation_types (code, name, regime, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, TRUE, NOW(), NOW()) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, regime = EXCLUDED.regime, sort_order = EXCLUDED.sort_order, updated_at = NOW()',
                [$row['code'], $row['name'], $row['regime'], $row['sort_order']]
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS master.sunat_operation_types');
    }
};
