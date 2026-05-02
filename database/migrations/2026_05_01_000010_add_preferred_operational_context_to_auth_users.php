<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddPreferredOperationalContextToAuthUsers extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE auth.users ADD COLUMN IF NOT EXISTS preferred_warehouse_id BIGINT NULL');
        DB::statement('ALTER TABLE auth.users ADD COLUMN IF NOT EXISTS preferred_cash_register_id BIGINT NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_auth_users_preferred_warehouse ON auth.users (preferred_warehouse_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_auth_users_preferred_cash_register ON auth.users (preferred_cash_register_id)');
    }

    public function down()
    {
        DB::statement('DROP INDEX IF EXISTS auth.idx_auth_users_preferred_warehouse');
        DB::statement('DROP INDEX IF EXISTS auth.idx_auth_users_preferred_cash_register');

        DB::statement('ALTER TABLE auth.users DROP COLUMN IF EXISTS preferred_warehouse_id');
        DB::statement('ALTER TABLE auth.users DROP COLUMN IF EXISTS preferred_cash_register_id');
    }
}
