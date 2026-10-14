<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompanyResponseDelaySettings extends Migration
{
    public function up()
    {
        Schema::table('appcfg.company_rate_limits', function (Blueprint $table) {
            if (!Schema::hasColumn('appcfg.company_rate_limits', 'response_delay_enabled')) {
                $table->boolean('response_delay_enabled')->default(false);
            }

            if (!Schema::hasColumn('appcfg.company_rate_limits', 'response_delay_ms')) {
                $table->unsignedInteger('response_delay_ms')->default(0);
            }
        });
    }

    public function down()
    {
        Schema::table('appcfg.company_rate_limits', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('appcfg.company_rate_limits', 'response_delay_enabled')) {
                $columns[] = 'response_delay_enabled';
            }
            if (Schema::hasColumn('appcfg.company_rate_limits', 'response_delay_ms')) {
                $columns[] = 'response_delay_ms';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
}
