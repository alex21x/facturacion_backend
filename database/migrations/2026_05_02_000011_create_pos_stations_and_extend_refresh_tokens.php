<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreatePosStationsAndExtendRefreshTokens extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE auth.refresh_tokens ADD COLUMN IF NOT EXISTS device_id VARCHAR(120) NULL');
        DB::statement('ALTER TABLE auth.refresh_tokens ADD COLUMN IF NOT EXISTS device_name VARCHAR(120) NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user_device ON auth.refresh_tokens (user_id, device_id)');

        DB::statement("CREATE SEQUENCE IF NOT EXISTS appcfg.pos_stations_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1");
        DB::statement(
            "CREATE TABLE IF NOT EXISTS appcfg.pos_stations (
                id BIGINT PRIMARY KEY DEFAULT nextval('appcfg.pos_stations_id_seq'),
                company_id BIGINT NOT NULL,
                cash_register_id BIGINT NOT NULL,
                code VARCHAR(30) NOT NULL,
                name VARCHAR(120) NOT NULL,
                device_id VARCHAR(120) NOT NULL,
                device_name VARCHAR(120) NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )"
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_pos_stations_company_code ON appcfg.pos_stations (company_id, code)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_pos_stations_company_device ON appcfg.pos_stations (company_id, device_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pos_stations_company_status ON appcfg.pos_stations (company_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pos_stations_company_cash_register ON appcfg.pos_stations (company_id, cash_register_id)');
    }

    public function down()
    {
        DB::statement('DROP INDEX IF EXISTS auth.idx_refresh_tokens_user_device');
        DB::statement('DROP INDEX IF EXISTS appcfg.idx_pos_stations_company_cash_register');
        DB::statement('DROP INDEX IF EXISTS appcfg.idx_pos_stations_company_status');
        DB::statement('DROP INDEX IF EXISTS appcfg.idx_pos_stations_company_device');
        DB::statement('DROP INDEX IF EXISTS appcfg.idx_pos_stations_company_code');

        DB::statement('DROP TABLE IF EXISTS appcfg.pos_stations');
        DB::statement('DROP SEQUENCE IF EXISTS appcfg.pos_stations_id_seq');

        DB::statement('ALTER TABLE auth.refresh_tokens DROP COLUMN IF EXISTS device_name');
        DB::statement('ALTER TABLE auth.refresh_tokens DROP COLUMN IF EXISTS device_id');
    }
}