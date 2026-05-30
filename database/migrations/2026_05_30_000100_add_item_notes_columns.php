<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sales.commercial_document_items') && !Schema::hasColumn('sales.commercial_document_items', 'notes')) {
            Schema::table('sales.commercial_document_items', function (Blueprint $table): void {
                $table->text('notes')->nullable();
            });
        }

        if (Schema::hasTable('inventory.stock_entry_items') && !Schema::hasColumn('inventory.stock_entry_items', 'notes')) {
            Schema::table('inventory.stock_entry_items', function (Blueprint $table): void {
                $table->text('notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales.commercial_document_items') && Schema::hasColumn('sales.commercial_document_items', 'notes')) {
            Schema::table('sales.commercial_document_items', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }

        if (Schema::hasTable('inventory.stock_entry_items') && Schema::hasColumn('inventory.stock_entry_items', 'notes')) {
            Schema::table('inventory.stock_entry_items', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }
    }
};
