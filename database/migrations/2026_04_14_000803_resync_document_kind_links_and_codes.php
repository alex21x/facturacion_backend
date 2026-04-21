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

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'series_numbers')->exists()) {
            DB::statement(<<<'SQL'
UPDATE sales.series_numbers sn
SET document_kind_id = dk.id
FROM sales.document_kinds dk
WHERE sn.document_kind_id IS NULL
  AND UPPER(TRIM(COALESCE(sn.document_kind, ''))) = UPPER(TRIM(COALESCE(dk.code, '')))
SQL
);

            DB::statement(<<<'SQL'
UPDATE sales.series_numbers sn
SET document_kind = dk.code
FROM sales.document_kinds dk
WHERE sn.document_kind_id = dk.id
  AND COALESCE(sn.document_kind, '') <> dk.code
SQL
);
        }

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'document_sequences')->exists()) {
            DB::statement(<<<'SQL'
UPDATE sales.document_sequences ds
SET document_kind_id = dk.id
FROM sales.document_kinds dk
WHERE ds.document_kind_id IS NULL
  AND UPPER(TRIM(COALESCE(ds.document_kind, ''))) = UPPER(TRIM(COALESCE(dk.code, '')))
SQL
);

            DB::statement(<<<'SQL'
UPDATE sales.document_sequences ds
SET document_kind = dk.code
FROM sales.document_kinds dk
WHERE ds.document_kind_id = dk.id
  AND COALESCE(ds.document_kind, '') <> dk.code
SQL
);
        }

        if (DB::table('information_schema.tables')->where('table_schema', 'sales')->where('table_name', 'commercial_documents')->exists()) {
            DB::statement(<<<'SQL'
UPDATE sales.commercial_documents cd
SET document_kind_id = dk.id
FROM sales.document_kinds dk
WHERE cd.document_kind_id IS NULL
  AND UPPER(TRIM(COALESCE(cd.document_kind, ''))) = UPPER(TRIM(COALESCE(dk.code, '')))
SQL
);

            DB::statement(<<<'SQL'
UPDATE sales.commercial_documents cd
SET document_kind = dk.code
FROM sales.document_kinds dk
WHERE cd.document_kind_id = dk.id
  AND COALESCE(cd.document_kind, '') <> dk.code
SQL
);
        }

        if (DB::table('information_schema.tables')->where('table_schema', 'billing')->where('table_name', 'documents')->exists()) {
            DB::statement(<<<'SQL'
UPDATE billing.documents bd
SET doc_type = dk.code
FROM sales.document_kinds dk
WHERE UPPER(TRIM(COALESCE(bd.doc_type, ''))) = UPPER(TRIM(COALESCE(dk.code, '')))
SQL
);
        }
    }

    public function down(): void
    {
        // No-op: reconciliation migration.
    }
};
