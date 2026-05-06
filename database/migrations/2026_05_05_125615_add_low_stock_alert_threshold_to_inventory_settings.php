<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('inventory_settings') && !Schema::hasColumn('inventory_settings', 'low_stock_alert_threshold')) {
            Schema::table('inventory_settings', function (Blueprint $table) {
                $table->integer('low_stock_alert_threshold')->default(5)->after('allow_negative_stock');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_settings', 'low_stock_alert_threshold')) {
            Schema::table('inventory_settings', function (Blueprint $table) {
                $table->dropColumn('low_stock_alert_threshold');
            });
        }
    }
};
