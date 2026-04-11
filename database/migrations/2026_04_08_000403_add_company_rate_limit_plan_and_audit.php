<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompanyRateLimitPlanAndAudit extends Migration
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
                $table->string('plan_code', 20)->nullable();
                $table->string('last_preset_code', 20)->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['is_enabled'], 'idx_company_rate_limits_enabled');
                $table->index(['plan_code'], 'idx_company_rate_limits_plan');
            });
        } else {
            Schema::table('appcfg.company_rate_limits', function (Blueprint $table) {
                if (!Schema::hasColumn('appcfg.company_rate_limits', 'plan_code')) {
                    $table->string('plan_code', 20)->nullable()->after('requests_per_minute_reports');
                }
                if (!Schema::hasColumn('appcfg.company_rate_limits', 'last_preset_code')) {
                    $table->string('last_preset_code', 20)->nullable()->after('plan_code');
                }
            });
        }

        if (!Schema::hasTable('appcfg.company_rate_limit_audit')) {
            Schema::create('appcfg.company_rate_limit_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('company_id');
                $table->string('action_type', 20);
                $table->string('plan_code', 20)->nullable();
                $table->string('preset_code', 20)->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('requests_per_minute_read');
                $table->unsignedInteger('requests_per_minute_write');
                $table->unsignedInteger('requests_per_minute_reports');
                $table->unsignedBigInteger('applied_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['company_id', 'created_at'], 'idx_rate_limit_audit_company_created');
                $table->index(['action_type'], 'idx_rate_limit_audit_action');
                $table->index(['plan_code'], 'idx_rate_limit_audit_plan');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('appcfg.company_rate_limit_audit')) {
            Schema::drop('appcfg.company_rate_limit_audit');
        }

        if (Schema::hasTable('appcfg.company_rate_limits')) {
            Schema::table('appcfg.company_rate_limits', function (Blueprint $table) {
                $drop = [];
                if (Schema::hasColumn('appcfg.company_rate_limits', 'plan_code')) {
                    $drop[] = 'plan_code';
                }
                if (Schema::hasColumn('appcfg.company_rate_limits', 'last_preset_code')) {
                    $drop[] = 'last_preset_code';
                }
                if (!empty($drop)) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
}
