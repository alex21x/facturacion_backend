<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();

echo "== ALL company_modules rows ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM appcfg.company_modules ORDER BY company_id, module_id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
if (empty($rows)) echo "(EMPTY - no module assignments)" . PHP_EOL;

echo PHP_EOL . "== RESTAURANT PRODUCTS (companies 4 and 7) ==" . PHP_EOL;
$rows = $pdo->query("
    SELECT p.id, p.company_id, p.name, p.product_nature, p.is_stockable, p.category_id, p.line_id, p.status,
           cat.name as cat_name
    FROM inventory.products p
    LEFT JOIN inventory.product_categories cat ON cat.id = p.category_id AND cat.company_id = p.company_id
    WHERE p.company_id IN (4,7) AND p.status = 1
    ORDER BY p.company_id, p.id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']}|id={$r['id']}|{$r['name']}|nature={$r['product_nature']}|stockable={$r['is_stockable']}|cat={$r['cat_name']}" . PHP_EOL;
}
if (empty($rows)) echo "(NO PRODUCTS)" . PHP_EOL;

echo PHP_EOL . "== company_feature_toggles for 4,6,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM appcfg.company_feature_toggles WHERE company_id IN (4,6,7) ORDER BY company_id, feature_code")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "{$r['company_id']}|{$r['feature_code']}|enabled={$r['is_enabled']}" . PHP_EOL; }
if (empty($rows)) echo "(EMPTY for companies 4,6,7)" . PHP_EOL;

echo PHP_EOL . "== VERTICALS table ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM appcfg.verticals")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }

echo PHP_EOL . "== company_verticals for 4,6,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM appcfg.company_verticals WHERE company_id IN (4,6,7) ORDER BY company_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }

echo PHP_EOL . "== restaurant.tables for 4,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM restaurant.tables WHERE company_id IN (4,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
