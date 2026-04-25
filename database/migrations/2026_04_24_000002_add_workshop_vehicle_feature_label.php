<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        DB::table('appcfg.feature_labels')->updateOrInsert(
            ['feature_code' => 'SALES_WORKSHOP_MULTI_VEHICLE'],
            [
                'label_es' => 'Taller: clientes con multiples vehiculos',
                'description' => 'Habilita registro y busqueda de vehiculos por cliente (placa, marca, modelo).',
                'status' => 1,
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        DB::table('appcfg.feature_labels')
            ->where('feature_code', 'SALES_WORKSHOP_MULTI_VEHICLE')
            ->delete();
    }
};
