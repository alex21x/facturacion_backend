<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales.commercial_document_print_cache')) {
            return;
        }

        Schema::create('sales.commercial_document_print_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->enum('format', ['ticket', 'a4'])->default('a4');
            $table->longText('html_content')->nullable();
            $table->binary('pdf_binary')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable()->index();

            $table->unique(['document_id', 'format'], 'unique_document_format_cache');
            $table->index(['company_id', 'expires_at'], 'idx_cache_cleanup_sweep');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.commercial_document_print_cache');
    }
};
