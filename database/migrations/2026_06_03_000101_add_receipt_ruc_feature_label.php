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
            ['feature_code' => 'SALES_ALLOW_RECEIPT_WITH_RUC'],
            [
                'label_es' => 'Permitir boleta con cliente RUC',
                'description' => 'Permite emitir boletas de venta para clientes con RUC valido de 11 digitos.',
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
            ->where('feature_code', 'SALES_ALLOW_RECEIPT_WITH_RUC')
            ->delete();
    }
};
