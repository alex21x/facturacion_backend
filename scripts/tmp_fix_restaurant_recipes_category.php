<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

DB::table('appcfg.feature_labels')
    ->where('feature_code', 'RESTAURANT_RECIPES_ENABLED')
    ->update([
        'category_key'   => 'restaurant',
        'category_label' => 'Restaurant',
        'category_order' => 10,
        'updated_at'     => now(),
    ]);

$row = DB::table('appcfg.feature_labels')
    ->where('feature_code', 'RESTAURANT_RECIPES_ENABLED')
    ->first(['feature_code', 'label_es', 'status', 'category_key', 'category_label', 'category_order']);

echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
