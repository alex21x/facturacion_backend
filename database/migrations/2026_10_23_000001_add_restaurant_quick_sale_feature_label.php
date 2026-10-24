<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('appcfg.feature_labels')->updateOrInsert(
            ['feature_code' => 'RESTAURANT_QUICK_SALE_ENABLED'],
            [
                'label_es' => 'Venta rapida desde pedidos',
                'description' => 'Permite iniciar el cierre de pedidos con Pedido de Venta como comprobante predeterminado.',
                'status' => 1,
                'category_key' => 'restaurant',
                'category_label' => 'Restaurante',
                'category_order' => 10,
            ]
        );
    }

    public function down(): void
    {
        DB::table('appcfg.feature_labels')
            ->where('feature_code', 'RESTAURANT_QUICK_SALE_ENABLED')
            ->delete();
    }
};