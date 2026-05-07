<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddUniqueIndexToPosStationsDeviceId extends Migration
{
    public function up()
    {
        DB::statement("DROP INDEX IF EXISTS appcfg.ux_pos_stations_device_id_normalized");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_pos_stations_company_device_id_normalized ON appcfg.pos_stations (company_id, LOWER(BTRIM(device_id)))");
    }

    public function down()
    {
        DB::statement("DROP INDEX IF EXISTS appcfg.ux_pos_stations_company_device_id_normalized");
    }
}
