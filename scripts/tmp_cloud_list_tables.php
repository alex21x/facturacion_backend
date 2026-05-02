<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();

echo "== ALL SCHEMAS AND TABLES ==" . PHP_EOL;
$rows = $pdo->query("SELECT table_schema, table_name FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema') ORDER BY table_schema, table_name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "{$r['table_schema']}.{$r['table_name']}" . PHP_EOL; }
