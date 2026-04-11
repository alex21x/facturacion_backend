<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWarehouseIdToCashRegisters extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('sales.cash_registers')) {
            return;
        }

        Schema::table('sales.cash_registers', function (Blueprint $table) {
            if (!Schema::hasColumn('sales.cash_registers', 'warehouse_id')) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('branch_id');
                $table->index(['company_id', 'warehouse_id'], 'idx_cash_registers_company_warehouse');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('sales.cash_registers')) {
            return;
        }

        Schema::table('sales.cash_registers', function (Blueprint $table) {
            if (Schema::hasColumn('sales.cash_registers', 'warehouse_id')) {
                $table->dropIndex('idx_cash_registers_company_warehouse');
                $table->dropColumn('warehouse_id');
            }
        });
    }
}
