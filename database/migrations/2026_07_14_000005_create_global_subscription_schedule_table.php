<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('appcfg.global_subscription_schedule')) {
            Schema::create('appcfg.global_subscription_schedule', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->string('alert_frequency', 10)->default('WEEKLY');
                $table->string('alert_time', 5)->default('08:00');
                $table->unsignedTinyInteger('weekly_digest_day')->default(1);
                $table->unsignedTinyInteger('monthly_digest_day')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            DB::table('appcfg.global_subscription_schedule')->insert([
                'id' => 1,
                'alert_frequency' => 'WEEKLY',
                'alert_time' => '08:00',
                'weekly_digest_day' => 1,
                'monthly_digest_day' => 1,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appcfg.global_subscription_schedule');
    }
};