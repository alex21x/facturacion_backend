<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanySubscriptionsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('appcfg.company_subscriptions')) {
            return;
        }

        Schema::create('appcfg.company_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->primary();
            $table->string('billing_cycle', 16)->default('NONE');
            $table->string('status', 16)->default('ACTIVE');
            $table->string('source', 16)->default('MANUAL');
            $table->boolean('alerts_enabled')->default(true);
            $table->string('enforcement_mode', 16)->default('NONE');
            $table->date('starts_at')->nullable();
            $table->date('current_period_starts_at')->nullable();
            $table->date('current_period_ends_at')->nullable();
            $table->unsignedSmallInteger('reminder_days_before')->default(3);
            $table->unsignedSmallInteger('grace_days')->default(5);
            $table->unsignedSmallInteger('soft_block_days_after')->default(7);
            $table->unsignedSmallInteger('hard_block_days_after')->default(15);
            $table->timestamp('last_pre_due_alert_at')->nullable();
            $table->timestamp('last_overdue_alert_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['alerts_enabled', 'status'], 'idx_company_subscriptions_alerts');
            $table->index(['current_period_ends_at'], 'idx_company_subscriptions_due_at');
            $table->index(['billing_cycle'], 'idx_company_subscriptions_cycle');
        });
    }

    public function down()
    {
        Schema::dropIfExists('appcfg.company_subscriptions');
    }
}