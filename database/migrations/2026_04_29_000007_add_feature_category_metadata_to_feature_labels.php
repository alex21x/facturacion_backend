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

        DB::statement('ALTER TABLE appcfg.feature_labels ADD COLUMN IF NOT EXISTS category_key VARCHAR(80) NULL');
        DB::statement('ALTER TABLE appcfg.feature_labels ADD COLUMN IF NOT EXISTS category_label VARCHAR(120) NULL');
        DB::statement('ALTER TABLE appcfg.feature_labels ADD COLUMN IF NOT EXISTS category_order INTEGER NOT NULL DEFAULT 100');

        DB::statement("\n            UPDATE appcfg.feature_labels\n               SET category_key = LOWER(SPLIT_PART(feature_code, '_', 1))\n             WHERE category_key IS NULL OR TRIM(category_key) = ''\n        ");

        DB::statement("\n            UPDATE appcfg.feature_labels\n               SET category_label = INITCAP(REPLACE(category_key, '_', ' '))\n             WHERE category_label IS NULL OR TRIM(category_label) = ''\n        ");
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('appcfg.feature_labels')) {
            return;
        }

        DB::statement('ALTER TABLE appcfg.feature_labels DROP COLUMN IF EXISTS category_order');
        DB::statement('ALTER TABLE appcfg.feature_labels DROP COLUMN IF EXISTS category_label');
        DB::statement('ALTER TABLE appcfg.feature_labels DROP COLUMN IF EXISTS category_key');
    }
};
