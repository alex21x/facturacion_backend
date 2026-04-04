<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS core.company_igv_rates (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                name varchar(120) NOT NULL,
                rate_percent numeric(8,4) NOT NULL,
                is_active boolean NOT NULL DEFAULT false,
                effective_from date NULL,
                created_at timestamp with time zone NOT NULL DEFAULT now(),
                updated_at timestamp with time zone NOT NULL DEFAULT now(),
                CONSTRAINT company_igv_rates_company_id_fkey
                    FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE
            )'
        );

        DB::statement('CREATE INDEX IF NOT EXISTS company_igv_rates_company_idx ON core.company_igv_rates (company_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS company_igv_rates_active_unique_idx ON core.company_igv_rates (company_id) WHERE is_active = true');

        DB::statement(
            "INSERT INTO core.company_igv_rates (company_id, name, rate_percent, is_active, effective_from, created_at, updated_at)
             SELECT c.id, 'IGV 18.00%', 18.0000, true, CURRENT_DATE, now(), now()
             FROM core.companies c
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM core.company_igv_rates r
                 WHERE r.company_id = c.id
                   AND r.is_active = true
             )"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS core.company_igv_rates_active_unique_idx');
        DB::statement('DROP INDEX IF EXISTS core.company_igv_rates_company_idx');
        DB::statement('DROP TABLE IF EXISTS core.company_igv_rates');
    }
};