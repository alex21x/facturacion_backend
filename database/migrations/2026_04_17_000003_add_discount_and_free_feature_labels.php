<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LABELS = [
        'SALES_GLOBAL_DISCOUNT_ENABLED' => 'Descuento global en ventas',
        'SALES_ITEM_DISCOUNT_ENABLED' => 'Descuento por item en ventas',
        'SALES_FREE_ITEMS_ENABLED' => 'Operaciones gratuitas en ventas',
        'PURCHASES_GLOBAL_DISCOUNT_ENABLED' => 'Descuento global en compras',
        'PURCHASES_ITEM_DISCOUNT_ENABLED' => 'Descuento por item en compras',
        'PURCHASES_FREE_ITEMS_ENABLED' => 'Operaciones gratuitas en compras',
    ];

    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        foreach (self::LABELS as $code => $label) {
            DB::table('appcfg.feature_labels')->updateOrInsert(
                ['feature_code' => $code],
                [
                    'label_es' => $label,
                    'description' => $label,
                    'status' => 1,
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        DB::table('appcfg.feature_labels')
            ->whereIn('feature_code', array_keys(self::LABELS))
            ->delete();
    }
};