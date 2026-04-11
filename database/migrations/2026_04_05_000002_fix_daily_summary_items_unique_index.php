<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales.daily_summary_items_doc_unique_idx');

        // Allow same document to appear in different summary types (RC and RA),
        // but never duplicated within the same summary header.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS daily_summary_items_summary_doc_unique_idx
            ON sales.daily_summary_items (summary_id, document_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales.daily_summary_items_summary_doc_unique_idx');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS daily_summary_items_doc_unique_idx
            ON sales.daily_summary_items (document_id)');
    }
};
