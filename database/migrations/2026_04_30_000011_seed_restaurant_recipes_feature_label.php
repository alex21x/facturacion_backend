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
            ['feature_code' => 'RESTAURANT_RECIPES_ENABLED'],
            [
                'label_es'       => 'Recetas de restaurante',
                'description'    => 'Habilita la validación de recetas e insumos al confirmar comandas de restaurante.',
                'status'         => 1,
                'category_key'   => 'restaurant',
                'category_label' => 'Restaurant',
                'category_order' => 10,
                'updated_at'     => now(),
            ]
        );
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        DB::table('appcfg.feature_labels')
            ->where('feature_code', 'RESTAURANT_RECIPES_ENABLED')
            ->delete();
    }
};
