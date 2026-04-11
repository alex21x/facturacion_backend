<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.gre_guides (
                id                  bigserial PRIMARY KEY,
                company_id          bigint           NOT NULL,
                branch_id           bigint,
                guide_type          varchar(20)      NOT NULL DEFAULT \'REMITENTE\',
                issue_date          date             NOT NULL,
                transfer_date       date,
                series              varchar(8)       NOT NULL DEFAULT \'T001\',
                number              bigint           NOT NULL,
                identifier          varchar(40)      NOT NULL DEFAULT \'\',
                status              varchar(30)      NOT NULL DEFAULT \'DRAFT\',
                notes               text,
                motivo_traslado     varchar(4)       NOT NULL DEFAULT \'01\',
                weight_kg           numeric(12,3)    NOT NULL DEFAULT 0,
                packages_count      int              NOT NULL DEFAULT 1,
                punto_partida       text             NOT NULL DEFAULT \'\',
                punto_llegada       text             NOT NULL DEFAULT \'\',
                transporter         jsonb,
                vehicle             jsonb,
                driver              jsonb,
                destinatario        jsonb,
                items               jsonb            NOT NULL DEFAULT \'[]\'::jsonb,
                bridge_method       varchar(100),
                bridge_endpoint     varchar(600),
                bridge_http_code    int,
                sunat_ticket        varchar(200),
                sunat_cdr_code      varchar(20),
                sunat_cdr_desc      text,
                raw_response        jsonb,
                sent_at             timestamp with time zone,
                cancelled_at        timestamp with time zone,
                cancelled_reason    text,
                created_by          bigint,
                updated_by          bigint,
                created_at          timestamp with time zone NOT NULL DEFAULT now(),
                updated_at          timestamp with time zone NOT NULL DEFAULT now()
            )'
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS gre_guides_identifier_unique_idx
            ON sales.gre_guides (company_id, identifier)');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS gre_guides_series_number_unique_idx
            ON sales.gre_guides (company_id, series, number)');

        DB::statement('CREATE INDEX IF NOT EXISTS gre_guides_company_issue_idx
            ON sales.gre_guides (company_id, issue_date DESC)');

        DB::statement('CREATE INDEX IF NOT EXISTS gre_guides_status_idx
            ON sales.gre_guides (status)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sales.gre_guides');
    }
};
