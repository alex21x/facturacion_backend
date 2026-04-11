<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExpandCompanyRateLimitsProfiles extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('appcfg.company_rate_limits')) {
            Schema::create('appcfg.company_rate_limits', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->primary();
                $table->unsignedInteger('requests_per_minute')->default(3600);
                $table->unsignedInteger('requests_per_minute_read')->nullable();
                $table->unsignedInteger('requests_per_minute_write')->nullable();
                $table->unsignedInteger('requests_per_minute_reports')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['is_enabled'], 'idx_company_rate_limits_enabled');
            });

            return;
        }

        Schema::table('appcfg.company_rate_limits', function (Blueprint $table) {
            if (!Schema::hasColumn('appcfg.company_rate_limits', 'requests_per_minute_read')) {
                $table->unsignedInteger('requests_per_minute_read')->nullable()->after('requests_per_minute');
            }
            if (!Schema::hasColumn('appcfg.company_rate_limits', 'requests_per_minute_write')) {
                $table->unsignedInteger('requests_per_minute_write')->nullable()->after('requests_per_minute_read');
            }
            if (!Schema::hasColumn('appcfg.company_rate_limits', 'requests_per_minute_reports')) {
                $table->unsignedInteger('requests_per_minute_reports')->nullable()->after('requests_per_minute_write');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('appcfg.company_rate_limits')) {
            return;
        }

        Schema::table('appcfg.company_rate_limits', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('appcfg.company_rate_limits', 'requests_per_minute_read')) {
                $drop[] = 'requests_per_minute_read';
            }
            if (Schema::hasColumn('appcfg.company_rate_limits', 'requests_per_minute_write')) {
                $drop[] = 'requests_per_minute_write';
            }
            if (Schema::hasColumn('appcfg.company_rate_limits', 'requests_per_minute_reports')) {
                $drop[] = 'requests_per_minute_reports';
            }

            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
}
