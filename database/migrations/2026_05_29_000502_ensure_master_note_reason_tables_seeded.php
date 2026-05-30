<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS master.credit_note_reasons (id BIGSERIAL PRIMARY KEY, code VARCHAR(10) NOT NULL, description VARCHAR(255) NOT NULL, status SMALLINT NOT NULL DEFAULT 1, is_deleted BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
        DB::statement("CREATE TABLE IF NOT EXISTS master.debit_note_reasons (id BIGSERIAL PRIMARY KEY, code VARCHAR(10) NOT NULL, description VARCHAR(255) NOT NULL, status SMALLINT NOT NULL DEFAULT 1, is_deleted BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");

        DB::statement('ALTER TABLE master.credit_note_reasons ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE master.credit_note_reasons ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE master.credit_note_reasons ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()');
        DB::statement('ALTER TABLE master.credit_note_reasons ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()');

        DB::statement('ALTER TABLE master.debit_note_reasons ADD COLUMN IF NOT EXISTS status SMALLINT NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE master.debit_note_reasons ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE master.debit_note_reasons ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()');
        DB::statement('ALTER TABLE master.debit_note_reasons ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS credit_note_reasons_code_unique ON master.credit_note_reasons (code)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS debit_note_reasons_code_unique ON master.debit_note_reasons (code)');

        $creditDefaults = [
            ['01', 'Anulacion de la operacion'],
            ['02', 'Anulacion por error en el RUC'],
            ['03', 'Correccion por error en la descripcion'],
            ['04', 'Descuento global'],
            ['05', 'Descuento por item'],
            ['06', 'Devolucion total'],
            ['07', 'Devolucion por item'],
            ['08', 'Bonificacion'],
            ['09', 'Disminucion en el valor'],
            ['10', 'Otros conceptos'],
        ];

        foreach ($creditDefaults as [$code, $description]) {
            DB::statement(
                'UPDATE master.credit_note_reasons SET description = ?, status = 1, updated_at = NOW() WHERE code = ?',
                [$description, $code]
            );

            DB::statement(
                'INSERT INTO master.credit_note_reasons (id, code, description, status, created_at, updated_at) SELECT COALESCE((SELECT MAX(id) FROM master.credit_note_reasons), 0) + 1, ?, ?, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM master.credit_note_reasons WHERE code = ?)',
                [$code, $description, $code]
            );
        }

        $debitDefaults = [
            ['01', 'Interes por mora'],
            ['02', 'Aumento en el valor'],
            ['03', 'Penalidades u otros conceptos'],
        ];

        foreach ($debitDefaults as [$code, $description]) {
            DB::statement(
                'UPDATE master.debit_note_reasons SET description = ?, status = 1, updated_at = NOW() WHERE code = ?',
                [$description, $code]
            );

            DB::statement(
                'INSERT INTO master.debit_note_reasons (id, code, description, status, created_at, updated_at) SELECT COALESCE((SELECT MAX(id) FROM master.debit_note_reasons), 0) + 1, ?, ?, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM master.debit_note_reasons WHERE code = ?)',
                [$code, $description, $code]
            );
        }
    }

    public function down(): void
    {
        // Baseline master catalog data should be preserved; no destructive rollback.
    }
};
