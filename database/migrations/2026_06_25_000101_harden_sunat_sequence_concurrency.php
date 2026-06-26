<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.void_communication_sequences (
                company_id bigint NOT NULL,
                sequence_date date NOT NULL,
                last_number int NOT NULL DEFAULT 0,
                created_at timestamp with time zone NOT NULL DEFAULT now(),
                updated_at timestamp with time zone NOT NULL DEFAULT now(),
                PRIMARY KEY (company_id, sequence_date)
            )'
        );

        DB::statement('CREATE INDEX IF NOT EXISTS void_comm_sequences_company_date_idx
            ON sales.void_communication_sequences (company_id, sequence_date DESC)');

        $hasDailySummaryCorrelationDuplicates = DB::table('sales.daily_summaries')
            ->select('company_id', 'summary_type', 'summary_date', 'correlation_number')
            ->groupBy('company_id', 'summary_type', 'summary_date', 'correlation_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (!$hasDailySummaryCorrelationDuplicates) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS daily_summaries_company_type_date_corr_unique_idx
                ON sales.daily_summaries (company_id, summary_type, summary_date, correlation_number)');
        } else {
            DB::statement('CREATE INDEX IF NOT EXISTS daily_summaries_company_type_date_corr_idx
                ON sales.daily_summaries (company_id, summary_type, summary_date, correlation_number)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales.daily_summaries_company_type_date_corr_unique_idx');
        DB::statement('DROP INDEX IF EXISTS sales.daily_summaries_company_type_date_corr_idx');
        DB::statement('DROP TABLE IF EXISTS sales.void_communication_sequences');
    }
};
