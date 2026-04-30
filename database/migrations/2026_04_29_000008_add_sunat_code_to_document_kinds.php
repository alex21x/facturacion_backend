<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive-only: create table if missing, add column if missing
        DB::statement("
            CREATE TABLE IF NOT EXISTS sales.document_kinds (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(30) NOT NULL UNIQUE,
                label VARCHAR(120) NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE sales.document_kinds ADD COLUMN IF NOT EXISTS sunat_code VARCHAR(4) NULL");

        $map = [
            'INVOICE'     => '01',
            'RECEIPT'     => '03',
            'CREDIT_NOTE' => '07',
            'DEBIT_NOTE'  => '08',
        ];

        foreach ($map as $code => $sunatCode) {
            DB::statement("
                INSERT INTO sales.document_kinds (code, label, sort_order, is_enabled, sunat_code, created_at, updated_at)
                VALUES (?, ?, ?, TRUE, ?, NOW(), NOW())
                ON CONFLICT (code) DO UPDATE SET sunat_code = EXCLUDED.sunat_code, updated_at = NOW()
            ", [$code, $code, 0, $sunatCode]);
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sales.document_kinds DROP COLUMN IF EXISTS sunat_code");
    }
};
