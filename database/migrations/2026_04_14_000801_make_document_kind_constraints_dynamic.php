<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_documents DROP CONSTRAINT IF EXISTS commercial_documents_document_kind_check');
        DB::statement('ALTER TABLE IF EXISTS sales.document_sequences DROP CONSTRAINT IF EXISTS document_sequences_document_kind_check');
        DB::statement('ALTER TABLE IF EXISTS sales.series_numbers DROP CONSTRAINT IF EXISTS series_numbers_document_kind_check');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE IF EXISTS sales.commercial_documents ADD CONSTRAINT commercial_documents_document_kind_check CHECK (document_kind IN ('QUOTATION','SALES_ORDER','INVOICE','RECEIPT','CREDIT_NOTE','DEBIT_NOTE'))");
        DB::statement("ALTER TABLE IF EXISTS sales.document_sequences ADD CONSTRAINT document_sequences_document_kind_check CHECK (document_kind IN ('QUOTATION','SALES_ORDER','INVOICE','RECEIPT','CREDIT_NOTE','DEBIT_NOTE'))");
        DB::statement("ALTER TABLE IF EXISTS sales.series_numbers ADD CONSTRAINT series_numbers_document_kind_check CHECK (document_kind IN ('QUOTATION','SALES_ORDER','INVOICE','RECEIPT','CREDIT_NOTE','DEBIT_NOTE'))");
    }
};
