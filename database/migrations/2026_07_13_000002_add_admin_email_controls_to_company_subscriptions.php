<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAdminEmailControlsToCompanySubscriptions extends Migration
{
    public function up()
    {
        Schema::table('appcfg.company_subscriptions', function (Blueprint $table) {
            $table->boolean('admin_email_enabled')->default(true);
            $table->text('admin_email_recipients')->nullable();
            $table->string('admin_email_frequency', 16)->default('DAILY');
            $table->unsignedSmallInteger('admin_email_send_hour')->default(8);
            $table->date('last_admin_digest_sent_on')->nullable();
            $table->string('last_admin_alert_state', 24)->nullable();
            $table->timestamp('last_admin_state_sent_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('appcfg.company_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'admin_email_enabled',
                'admin_email_recipients',
                'admin_email_frequency',
                'admin_email_send_hour',
                'last_admin_digest_sent_on',
                'last_admin_alert_state',
                'last_admin_state_sent_at',
            ]);
        });
    }
}