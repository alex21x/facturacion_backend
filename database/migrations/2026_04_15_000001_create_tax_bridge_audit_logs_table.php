<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auditoría de TODOS los envíos tributarios (RA, RC, BD, RD, NC, ND, facturas, boletas)
        Schema::create('sales.tax_bridge_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            // Scope empresa
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            
            // Documento tributario asociado
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('document_kind', 50)->nullable(); // INVOICE, RECEIPT, CREDIT_NOTE, DEBIT_NOTE, RA, RC, etc.
            $table->string('document_series', 10)->nullable();
            $table->string('document_number', 20)->nullable();
            
            // Tipo de envío tributario
            $table->enum('tributary_type', [
                'SUNAT_DIRECT',         // Envío directo a SUNAT
                'DETRACCION',           // Detracciones (GRD)
                'RETENCION',            // Retenciones (GRR)
                'PERCEPCION',           // Percepciones (GRP)
                'SUMMARY_RA',           // Resumen de Almacén (RA)
                'SUMMARY_RC',           // Resumen de Compras (RC)
                'RESUMEN_BOLETAS',      // Resumen de Boletas (RB)
                'REMISION_GUIA',        // Remisión/Guía
            ])->index();
            
            // Información del puente/endpoint
            $table->string('bridge_mode', 20)->nullable(); // PRODUCTION, BETA
            $table->string('endpoint_url', 500)->nullable();
            $table->string('http_method', 10)->default('POST');
            $table->string('content_type', 100)->default('application/x-www-form-urlencoded');
            
            // REQUEST (payload enviado)
            $table->longText('request_payload'); // JSON completo enviado
            $table->unsignedInteger('request_size_bytes')->nullable();
            $table->string('request_sha1_hash', 40)->nullable();
            
            // RESPONSE (respuesta recibida)
            $table->longText('response_body')->nullable(); // JSON/XML completo recibido
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->decimal('response_time_ms', 10, 2)->nullable(); // Cuánto tardó la petición en ms
            
            // Parseo de response (para filtros rápidos)
            $table->string('sunat_status', 50)->nullable()->index(); // ACCEPTED, REJECTED, PENDING_CONFIRMATION, ERROR
            $table->string('sunat_code', 10)->nullable(); // 0, 1, 3, código de error SUNAT
            $table->string('ticket_number', 100)->nullable(); // Ticket si aplica
            $table->string('cdr_code', 50)->nullable(); // Código CDR si aplica
            $table->text('sunat_message')->nullable(); // Mensaje de SUNAT
            
            // Metadata y debugging
            $table->text('request_form_data')->nullable(); // Form data si se usa form encoding
            $table->text('auth_scheme')->nullable(); // none, bearer, etc
            $table->text('debug_notes')->nullable(); // Notas de debugging
            $table->text('error_message')->nullable(); // Si hubo error de envío
            $table->string('error_kind', 50)->nullable(); // NETWORK_ERROR, HTTP_ERROR, TIMEOUT, PARSE_ERROR, etc
            
            // Intento/reintentos
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->boolean('is_retry')->default(false);
            $table->boolean('is_manual_dispatch')->default(false); // Fue enviado manualmente por usuario
            
            // Usuarios
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->string('initiated_by_username', 100)->nullable();
            
            // Timestamps
            $table->timestamp('sent_at')->index(); // Cuándo se envió
            $table->timestamp('received_at')->nullable(); // Cuándo se recibió response
            $table->timestamps(); // created_at, updated_at
            
            // Índices para queries comunes
            $table->index(['company_id', 'sent_at']);
            $table->index(['company_id', 'branch_id', 'tributary_type']);
            $table->index(['document_id', 'attempt_number']);
            $table->index(['sunat_status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.tax_bridge_audit_logs');
    }
};
