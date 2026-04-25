<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// List actual columns in auth.users
$cols = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema='auth' AND table_name='users' ORDER BY ordinal_position");
echo "Columns in auth.users:\n";
foreach ($cols as $c) { echo "  {$c->column_name} ({$c->data_type})\n"; }

// Get raw row as stdClass
$raw = DB::select("SELECT * FROM auth.users WHERE username = 'admin_panel' AND status = 1");
echo "\nRaw row (array keys):\n";
foreach ($raw as $r) {
    echo json_encode((array)$r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

// Verify hash
if (!empty($raw)) {
    $r = $raw[0];
    $arr = (array)$r;
    foreach ($arr as $k => $v) {
        if (str_contains(strtolower($k), 'pass')) {
            echo "\nField '$k' value: $v\n";
            echo "Hash check 'Admin1234!': " . (Hash::check('Admin1234!', $v) ? "TRUE" : "FALSE") . "\n";
        }
    }
}
