<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSunatExceptionActionsTable extends Migration
{
    public function up()
    {
        Schema::create('sales.sunat_exception_actions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('document_id');
            $table->string('action_type', 40);
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40)->nullable();
            $table->string('evidence_type', 30)->nullable();
            $table->string('evidence_ref', 500)->nullable();
            $table->string('evidence_note', 1000)->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->dateTime('performed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'document_id'], 'sunat_ex_actions_company_doc_idx');
            $table->index(['company_id', 'created_at'], 'sunat_ex_actions_company_created_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales.sunat_exception_actions');
    }
}
