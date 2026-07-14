<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class SetCompanySubscriptionAdminEmailDisabledDefault extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE appcfg.company_subscriptions ALTER COLUMN admin_email_enabled SET DEFAULT false');
    }

    public function down()
    {
        DB::statement('ALTER TABLE appcfg.company_subscriptions ALTER COLUMN admin_email_enabled SET DEFAULT true');
    }
}
