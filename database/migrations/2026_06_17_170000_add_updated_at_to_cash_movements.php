<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales.cash_movements')) {
            return;
        }

        if (!Schema::hasColumn('sales.cash_movements', 'updated_at')) {
            Schema::table('sales.cash_movements', function (Blueprint $table): void {
                $table->timestampTz('updated_at')->nullable();
            });
        }

        DB::table('sales.cash_movements')
            ->whereNull('updated_at')
            ->update([
                'updated_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales.cash_movements')) {
            return;
        }

        if (Schema::hasColumn('sales.cash_movements', 'updated_at')) {
            Schema::table('sales.cash_movements', function (Blueprint $table): void {
                $table->dropColumn('updated_at');
            });
        }
    }
};
