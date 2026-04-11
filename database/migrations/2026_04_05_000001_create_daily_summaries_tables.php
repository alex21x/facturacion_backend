<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── daily_summaries ────────────────────────────────────────────────────
        // summary_type: 1 = RC (Resumen de Comprobantes / Declaración)
        //               3 = RA (Resumen de Anulaciones  / Anulación)
        // status: DRAFT | SENDING | SENT | ACCEPTED | REJECTED | ERROR
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.daily_summaries (
                id                  bigserial        PRIMARY KEY,
                company_id          bigint           NOT NULL,
                branch_id           bigint,
                summary_type        smallint         NOT NULL DEFAULT 1,
                summary_date        date             NOT NULL,
                correlation_number  int              NOT NULL DEFAULT 1,
                identifier          varchar(50)      NOT NULL DEFAULT \'\',
                status              varchar(30)      NOT NULL DEFAULT \'DRAFT\',
                sunat_ticket        varchar(200),
                sunat_cdr_code      varchar(20),
                sunat_cdr_desc      text,
                bridge_endpoint     varchar(600),
                bridge_http_code    int,
                raw_response        jsonb,
                notes               text,
                created_by          bigint,
                sent_at             timestamp with time zone,
                created_at          timestamp with time zone NOT NULL DEFAULT now(),
                updated_at          timestamp with time zone NOT NULL DEFAULT now()
            )'
        );

        DB::statement('CREATE INDEX IF NOT EXISTS daily_summaries_company_date_idx
            ON sales.daily_summaries (company_id, summary_date DESC)');

        DB::statement('CREATE INDEX IF NOT EXISTS daily_summaries_company_type_idx
            ON sales.daily_summaries (company_id, summary_type)');

        DB::statement('CREATE INDEX IF NOT EXISTS daily_summaries_status_idx
            ON sales.daily_summaries (status)');

        // ── daily_summary_items ────────────────────────────────────────────────
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.daily_summary_items (
                id          bigserial PRIMARY KEY,
                summary_id  bigint    NOT NULL,
                document_id bigint    NOT NULL,
                item_status smallint  NOT NULL DEFAULT 1,
                created_at  timestamp with time zone NOT NULL DEFAULT now()
            )'
        );

        DB::statement('CREATE INDEX IF NOT EXISTS daily_summary_items_summary_idx
            ON sales.daily_summary_items (summary_id)');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS daily_summary_items_doc_unique_idx
            ON sales.daily_summary_items (document_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sales.daily_summary_items');
        DB::statement('DROP TABLE IF EXISTS sales.daily_summaries');
    }
};
