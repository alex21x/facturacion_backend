<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS core.ubigeos (
    id bigserial PRIMARY KEY,
    code varchar(6) NOT NULL,
    district varchar(140) NOT NULL,
    province varchar(140) NOT NULL,
    department varchar(140) NOT NULL,
    full_name varchar(400),
    status smallint NOT NULL DEFAULT 1,
    source varchar(40) NOT NULL DEFAULT 'legacy',
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now()
)
SQL
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ubigeos_code_unique_idx ON core.ubigeos (code)');
        DB::statement('CREATE INDEX IF NOT EXISTS ubigeos_search_idx ON core.ubigeos (department, province, district)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS core.ubigeos');
    }
};
