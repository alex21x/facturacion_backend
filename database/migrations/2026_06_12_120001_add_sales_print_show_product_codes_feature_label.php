<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        $payload = [
            'label_es' => 'Mostrar codigos de producto en formatos A4/Ticket',
            'description' => 'Controla si los codigos de producto se imprimen en comprobantes A4 y ticket.',
            'status' => 1,
            'updated_at' => now(),
        ];

        if (DB::getSchemaBuilder()->hasColumn('appcfg.feature_labels', 'category_key')) {
            $payload['category_key'] = 'sales';
        }

        if (DB::getSchemaBuilder()->hasColumn('appcfg.feature_labels', 'category_label')) {
            $payload['category_label'] = 'Sales';
        }

        if (DB::getSchemaBuilder()->hasColumn('appcfg.feature_labels', 'category_order')) {
            $payload['category_order'] = 20;
        }

        if (DB::getSchemaBuilder()->hasColumn('appcfg.feature_labels', 'created_at')) {
            $payload['created_at'] = now();
        }

        DB::table('appcfg.feature_labels')->updateOrInsert(
            ['feature_code' => 'SALES_PRINT_SHOW_PRODUCT_CODES'],
            $payload
        );
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        DB::table('appcfg.feature_labels')
            ->where('feature_code', 'SALES_PRINT_SHOW_PRODUCT_CODES')
            ->delete();
    }
};