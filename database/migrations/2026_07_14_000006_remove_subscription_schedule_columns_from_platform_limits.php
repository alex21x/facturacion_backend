<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('appcfg.platform_limits')) {
            return;
        }

        Schema::table('appcfg.platform_limits', function (Blueprint $table) {
            if (Schema::hasColumn('appcfg.platform_limits', 'company_subscription_monthly_digest_day')) {
                $table->dropColumn('company_subscription_monthly_digest_day');
            }
            if (Schema::hasColumn('appcfg.platform_limits', 'company_subscription_weekly_digest_day')) {
                $table->dropColumn('company_subscription_weekly_digest_day');
            }
            if (Schema::hasColumn('appcfg.platform_limits', 'company_subscription_alert_time')) {
                $table->dropColumn('company_subscription_alert_time');
            }
            if (Schema::hasColumn('appcfg.platform_limits', 'company_subscription_alert_frequency')) {
                $table->dropColumn('company_subscription_alert_frequency');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('appcfg.platform_limits')) {
            return;
        }

        Schema::table('appcfg.platform_limits', function (Blueprint $table) {
            if (!Schema::hasColumn('appcfg.platform_limits', 'company_subscription_alert_frequency')) {
                $table->string('company_subscription_alert_frequency', 10)->default('WEEKLY');
            }
            if (!Schema::hasColumn('appcfg.platform_limits', 'company_subscription_alert_time')) {
                $table->string('company_subscription_alert_time', 5)->default('08:00');
            }
            if (!Schema::hasColumn('appcfg.platform_limits', 'company_subscription_weekly_digest_day')) {
                $table->unsignedTinyInteger('company_subscription_weekly_digest_day')->default(1);
            }
            if (!Schema::hasColumn('appcfg.platform_limits', 'company_subscription_monthly_digest_day')) {
                $table->unsignedTinyInteger('company_subscription_monthly_digest_day')->default(1);
            }
        });
    }
};