<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'document_kinds')->doesntExist()) {
            return;
        }

        DB::statement("CREATE SEQUENCE IF NOT EXISTS sales.document_kinds_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1");
        DB::statement("ALTER TABLE sales.document_kinds ADD COLUMN IF NOT EXISTS id BIGINT");
        DB::statement("ALTER TABLE sales.document_kinds ALTER COLUMN id SET DEFAULT nextval('sales.document_kinds_id_seq')");
        DB::statement("UPDATE sales.document_kinds SET id = nextval('sales.document_kinds_id_seq') WHERE id IS NULL");
        DB::statement("ALTER TABLE sales.document_kinds ALTER COLUMN id SET NOT NULL");
        DB::statement("SELECT setval('sales.document_kinds_id_seq', COALESCE((SELECT MAX(id) FROM sales.document_kinds), 1), true)");

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'document_kinds_pkey'
          AND conrelid = 'sales.document_kinds'::regclass
    ) THEN
        ALTER TABLE sales.document_kinds ADD CONSTRAINT document_kinds_pkey PRIMARY KEY (id);
    END IF;
END $$;
SQL
);

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'document_kinds_code_key'
          AND conrelid = 'sales.document_kinds'::regclass
    ) THEN
        ALTER TABLE sales.document_kinds ADD CONSTRAINT document_kinds_code_key UNIQUE (code);
    END IF;
END $$;
SQL
);

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'series_numbers')->exists()) {
            DB::statement('ALTER TABLE sales.series_numbers ADD COLUMN IF NOT EXISTS document_kind_id BIGINT');
            DB::statement(<<<'SQL'
UPDATE sales.series_numbers sn
SET document_kind_id = dk.id
FROM sales.document_kinds dk
WHERE sn.document_kind_id IS NULL
  AND UPPER(TRIM(COALESCE(sn.document_kind, ''))) = UPPER(TRIM(COALESCE(dk.code, '')))
SQL
);
            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'series_numbers_document_kind_id_fkey'
          AND conrelid = 'sales.series_numbers'::regclass
    ) THEN
        ALTER TABLE sales.series_numbers
            ADD CONSTRAINT series_numbers_document_kind_id_fkey
            FOREIGN KEY (document_kind_id) REFERENCES sales.document_kinds(id);
    END IF;
END $$;
SQL
);
        }

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'document_sequences')->exists()) {
            DB::statement('ALTER TABLE sales.document_sequences ADD COLUMN IF NOT EXISTS document_kind_id BIGINT');
            DB::statement(<<<'SQL'
UPDATE sales.document_sequences ds
SET document_kind_id = dk.id
FROM sales.document_kinds dk
WHERE ds.document_kind_id IS NULL
  AND UPPER(TRIM(COALESCE(ds.document_kind, ''))) = UPPER(TRIM(COALESCE(dk.code, '')))
SQL
);
            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'document_sequences_document_kind_id_fkey'
          AND conrelid = 'sales.document_sequences'::regclass
    ) THEN
        ALTER TABLE sales.document_sequences
            ADD CONSTRAINT document_sequences_document_kind_id_fkey
            FOREIGN KEY (document_kind_id) REFERENCES sales.document_kinds(id);
    END IF;
END $$;
SQL
);
        }

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'commercial_documents')->exists()) {
            DB::statement('ALTER TABLE sales.commercial_documents ADD COLUMN IF NOT EXISTS document_kind_id BIGINT');
            DB::statement(<<<'SQL'
UPDATE sales.commercial_documents cd
SET document_kind_id = dk.id
FROM sales.document_kinds dk
WHERE cd.document_kind_id IS NULL
  AND UPPER(TRIM(COALESCE(cd.document_kind, ''))) = UPPER(TRIM(COALESCE(dk.code, '')))
SQL
);
            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'commercial_documents_document_kind_id_fkey'
          AND conrelid = 'sales.commercial_documents'::regclass
    ) THEN
        ALTER TABLE sales.commercial_documents
            ADD CONSTRAINT commercial_documents_document_kind_id_fkey
            FOREIGN KEY (document_kind_id) REFERENCES sales.document_kinds(id);
    END IF;
END $$;
SQL
);
        }
    }

    public function down(): void
    {
        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'commercial_documents')->exists()) {
            DB::statement('ALTER TABLE sales.commercial_documents DROP CONSTRAINT IF EXISTS commercial_documents_document_kind_id_fkey');
            DB::statement('ALTER TABLE sales.commercial_documents DROP COLUMN IF EXISTS document_kind_id');
        }

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'document_sequences')->exists()) {
            DB::statement('ALTER TABLE sales.document_sequences DROP CONSTRAINT IF EXISTS document_sequences_document_kind_id_fkey');
            DB::statement('ALTER TABLE sales.document_sequences DROP COLUMN IF EXISTS document_kind_id');
        }

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'series_numbers')->exists()) {
            DB::statement('ALTER TABLE sales.series_numbers DROP CONSTRAINT IF EXISTS series_numbers_document_kind_id_fkey');
            DB::statement('ALTER TABLE sales.series_numbers DROP COLUMN IF EXISTS document_kind_id');
        }
    }
};
