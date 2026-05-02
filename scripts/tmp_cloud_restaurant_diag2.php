<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();

// Helper to show columns of a table
function showColumns($pdo, $schema, $table) {
    $rows = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='$schema' AND table_name='$table' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    echo "  $schema.$table columns: " . implode(', ', $rows) . PHP_EOL;
}

echo "== SCHEMA: company_modules ==" . PHP_EOL;
showColumns($pdo, 'appcfg', 'company_modules');
$rows = $pdo->query("SELECT * FROM appcfg.company_modules WHERE company_id IN (1,4,6,7) LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }

echo PHP_EOL . "== SCHEMA: company_feature_toggles ==" . PHP_EOL;
showColumns($pdo, 'appcfg', 'company_feature_toggles');
$rows = $pdo->query("SELECT * FROM appcfg.company_feature_toggles WHERE company_id IN (1,4,6,7) LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }

echo PHP_EOL . "== RESTAURANT PRODUCTS (company 4 and 7) ==" . PHP_EOL;
showColumns($pdo, 'inventory', 'products');
$rows = $pdo->query("SELECT id, company_id, name, product_type, category_id, line_id, status FROM inventory.products WHERE company_id IN (4,7) AND status=1 ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "{$r['company_id']}|id={$r['id']}|{$r['name']}|type={$r['product_type']}|cat={$r['category_id']}|line={$r['line_id']}" . PHP_EOL; }

echo PHP_EOL . "== PRODUCT CATEGORIES for companies 4,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT id, company_id, name FROM inventory.product_categories WHERE company_id IN (4,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "{$r['company_id']}|cat_id={$r['id']}|{$r['name']}" . PHP_EOL; }

echo PHP_EOL . "== PRODUCT LINES for companies 4,7 ==" . PHP_EOL;
try {
    $rows = $pdo->query("SELECT id, company_id, name FROM inventory.product_lines WHERE company_id IN (4,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { echo "{$r['company_id']}|line_id={$r['id']}|{$r['name']}" . PHP_EOL; }
} catch (Exception $e) { echo "No product_lines: " . $e->getMessage() . PHP_EOL; }

echo PHP_EOL . "== VERTICALS table ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM appcfg.verticals")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
