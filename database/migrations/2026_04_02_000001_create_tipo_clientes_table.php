<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core.tipo_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_cliente')->unique()->comment('Nombre del tipo de cliente (ej: Persona Natural, Persona Jurídica)');
            $table->integer('codigo')->unique()->comment('Código SUNAT para el tipo de documento');
            $table->string('abr_standar')->nullable()->comment('Abreviatura estándar');
            $table->boolean('activo')->default(true)->comment('Indica si el tipo está activo');
            $table->timestamps();
        });

        DB::table('core.tipo_clientes')->insert([
            [
                'tipo_cliente' => 'Persona Natural',
                'codigo' => 1,
                'abr_standar' => 'DOC.NACIONAL DE IDEN',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tipo_cliente' => 'Persona Jurídica',
                'codigo' => 6,
                'abr_standar' => 'REG. UNICO DE CONTRI',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tipo_cliente' => 'Empresas Del Extranjero',
                'codigo' => 0,
                'abr_standar' => 'DOC.TRIB.NO.DOM.SIN',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tipo_cliente' => 'Carnet de Extranjeria',
                'codigo' => 4,
                'abr_standar' => 'CARNET DE EXTRANJERIA',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tipo_cliente' => 'Pasaporte',
                'codigo' => 7,
                'abr_standar' => 'PASAPORTE',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tipo_cliente' => 'Otros',
                'codigo' => 8,
                'abr_standar' => 'OTROS',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('core.tipo_clientes');
    }
};
