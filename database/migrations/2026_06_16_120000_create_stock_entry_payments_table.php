<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory.stock_entry_payments')) {
            return;
        }

        Schema::create('inventory.stock_entry_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('stock_entry_id');
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('notes', 300)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();

            $table->index(['stock_entry_id', 'id'], 'idx_stock_entry_payments_entry_id');
            $table->index(['stock_entry_id', 'status'], 'idx_stock_entry_payments_entry_status');
            $table->foreign('stock_entry_id')->references('id')->on('inventory.stock_entries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory.stock_entry_payments');
    }
};
