<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('information_schema.tables')
            ->where('table_schema', 'sales')
            ->where('table_name', 'document_kinds')
            ->exists()) {
            return;
        }

        if (!DB::table('information_schema.columns')
            ->where('table_schema', 'sales')
            ->where('table_name', 'document_kinds')
            ->where('column_name', 'sunat_code')
            ->exists()) {
            return;
        }

        DB::statement("UPDATE sales.document_kinds SET sunat_code = '07', updated_at = NOW() WHERE UPPER(TRIM(code)) LIKE 'CREDIT_NOTE%'");
        DB::statement("UPDATE sales.document_kinds SET sunat_code = '08', updated_at = NOW() WHERE UPPER(TRIM(code)) LIKE 'DEBIT_NOTE%'");
    }

    public function down(): void
    {
        // Non-destructive rollback: keep normalized values.
    }
};
