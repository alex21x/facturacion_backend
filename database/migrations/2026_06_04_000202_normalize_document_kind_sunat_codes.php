<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class NormalizeDocumentKindSunatCodes extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('sales', 'document_kinds') || !$this->columnExists('sales', 'document_kinds', 'sunat_code')) {
            return;
        }

        DB::table('sales.document_kinds')
            ->whereRaw("UPPER(TRIM(code)) LIKE 'CREDIT_NOTE%'")
            ->where(function ($query) {
                $query->whereNull('sunat_code')
                    ->orWhere('sunat_code', '<>', '07');
            })
            ->update(['sunat_code' => '07']);

        DB::table('sales.document_kinds')
            ->whereRaw("UPPER(TRIM(code)) LIKE 'DEBIT_NOTE%'")
            ->where(function ($query) {
                $query->whereNull('sunat_code')
                    ->orWhere('sunat_code', '<>', '08');
            })
            ->update(['sunat_code' => '08']);
    }

    public function down(): void
    {
        // Data normalization only; no safe deterministic rollback.
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }
}
