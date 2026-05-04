<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();

echo "== inventory.categories schema ==" . PHP_EOL;
$rows = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='inventory' AND table_name='categories' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $rows) . PHP_EOL;

echo PHP_EOL . "== inventory.categories for companies 4,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT id, company_id, name FROM inventory.categories WHERE company_id IN (4,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "{$r['company_id']}|cat_id={$r['id']}|{$r['name']}" . PHP_EOL; }
if (empty($rows)) echo "(NO CATEGORIES)" . PHP_EOL;

echo PHP_EOL . "== inventory.product_lines for companies 4,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT id, company_id, name FROM inventory.product_lines WHERE company_id IN (4,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "{$r['company_id']}|line_id={$r['id']}|{$r['name']}" . PHP_EOL; }
if (empty($rows)) echo "(NO LINES)" . PHP_EOL;

echo PHP_EOL . "== inventory.products for companies 4,7 ==" . PHP_EOL;
$rows = $pdo->query("
    SELECT p.id, p.company_id, p.name, p.product_nature, p.is_stockable, p.category_id, p.line_id, p.status, p.sale_price
    FROM inventory.products p
    WHERE p.company_id IN (4,7) AND p.status = 1
    ORDER BY p.company_id, p.id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']}|id={$r['id']}|{$r['name']}|nature={$r['product_nature']}|stockable={$r['is_stockable']}|cat={$r['category_id']}|line={$r['line_id']}|price={$r['sale_price']}" . PHP_EOL;
}
if (empty($rows)) echo "(NO PRODUCTS)" . PHP_EOL;

echo PHP_EOL . "== sales.series_numbers for companies 4,7 (SALES_ORDER) ==" . PHP_EOL;
$rows = $pdo->query("SELECT id, company_id, document_kind, series, current_number, is_enabled FROM sales.series_numbers WHERE company_id IN (4,7) ORDER BY company_id, document_kind")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
if (empty($rows)) echo "(NO SERIES - orders cannot be created!)" . PHP_EOL;

echo PHP_EOL . "== core.branches for companies 4,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT id, company_id, name, status FROM core.branches WHERE company_id IN (4,7)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
if (empty($rows)) echo "(NO BRANCHES)" . PHP_EOL;

echo PHP_EOL . "== auth.users for companies 4,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT id, company_id, username, email, status FROM auth.users WHERE company_id IN (4,7) ORDER BY company_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "{$r['company_id']}|id={$r['id']}|{$r['username']}|{$r['email']}|status={$r['status']}" . PHP_EOL; }
if (empty($rows)) echo "(NO USERS - cannot login!)" . PHP_EOL;
