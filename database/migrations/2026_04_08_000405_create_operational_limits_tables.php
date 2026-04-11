<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateOperationalLimitsTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('appcfg.platform_limits')) {
            Schema::create('appcfg.platform_limits', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedInteger('max_companies_enabled')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            DB::table('appcfg.platform_limits')->insert([
                'id' => 1,
                'max_companies_enabled' => 1,
                'updated_at' => now(),
            ]);
        }

        if (!Schema::hasTable('appcfg.company_operational_limits')) {
            Schema::create('appcfg.company_operational_limits', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->primary();
                $table->unsignedInteger('max_branches_enabled')->default(1);
                $table->unsignedInteger('max_warehouses_enabled')->default(1);
                $table->unsignedInteger('max_cash_registers_enabled')->default(1);
                $table->unsignedInteger('max_cash_registers_per_warehouse')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index(['max_branches_enabled'], 'idx_company_limits_branches');
                $table->index(['max_warehouses_enabled'], 'idx_company_limits_warehouses');
                $table->index(['max_cash_registers_enabled'], 'idx_company_limits_cash');
            });
        } else {
            Schema::table('appcfg.company_operational_limits', function (Blueprint $table) {
                if (!Schema::hasColumn('appcfg.company_operational_limits', 'max_cash_registers_per_warehouse')) {
                    $table->unsignedInteger('max_cash_registers_per_warehouse')->default(1);
                }
            });
        }

        if (Schema::hasTable('core.companies') && Schema::hasTable('appcfg.company_operational_limits')) {
            $companyIds = DB::table('core.companies')->pluck('id')->all();
            foreach ($companyIds as $companyId) {
                DB::table('appcfg.company_operational_limits')->updateOrInsert(
                    ['company_id' => (int) $companyId],
                    [
                        'max_branches_enabled' => 1,
                        'max_warehouses_enabled' => 1,
                        'max_cash_registers_enabled' => 1,
                        'max_cash_registers_per_warehouse' => 1,
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down()
    {
        if (Schema::hasTable('appcfg.company_operational_limits')) {
            Schema::drop('appcfg.company_operational_limits');
        }

        if (Schema::hasTable('appcfg.platform_limits')) {
            Schema::drop('appcfg.platform_limits');
        }
    }
}
