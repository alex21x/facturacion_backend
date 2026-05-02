<?php
// Deep diagnostic for restaurant demo visibility
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = DB::connection()->getPdo();

echo "== COMPANY MODULES (restaurant-related) ==" . PHP_EOL;
$rows = $pdo->query("
    SELECT cm.company_id, c.legal_name, cm.module_id, cm.status
    FROM appcfg.company_modules cm
    JOIN core.companies c ON c.id = cm.company_id
    WHERE cm.company_id IN (1,4,6,7)
    ORDER BY cm.company_id, cm.module_id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']} | {$r['legal_name']} | module={$r['module_id']} | status={$r['status']}" . PHP_EOL;
}

echo PHP_EOL . "== RESTAURANT PRODUCTS DETAIL (company 4, 7) ==" . PHP_EOL;
$rows = $pdo->query("
    SELECT p.company_id, p.id, p.name, p.product_type, p.status,
           cat.name as category_name,
           l.name as line_name
    FROM inventory.products p
    LEFT JOIN inventory.product_categories cat ON cat.id = p.category_id AND cat.company_id = p.company_id
    LEFT JOIN inventory.product_lines l ON l.id = p.line_id AND l.company_id = p.company_id
    WHERE p.company_id IN (4, 7) AND p.status = 1
    ORDER BY p.company_id, p.id
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']} | id={$r['id']} | {$r['name']} | type={$r['product_type']} | cat={$r['category_name']} | line={$r['line_name']}" . PHP_EOL;
}

echo PHP_EOL . "== RESTAURANT TABLES DETAIL ==" . PHP_EOL;
$rows = $pdo->query("
    SELECT t.company_id, t.id, t.name, t.status
    FROM restaurant.tables t
    WHERE t.company_id IN (1,4,6,7)
    ORDER BY t.company_id, t.id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']} | table_id={$r['id']} | {$r['name']} | status={$r['status']}" . PHP_EOL;
}

echo PHP_EOL . "== RESTAURANT ORDERS (recent) ==" . PHP_EOL;
try {
    $rows = $pdo->query("
        SELECT company_id, id, status, created_at
        FROM restaurant.orders
        WHERE company_id IN (1,4,6,7)
        ORDER BY created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "{$r['company_id']} | order_id={$r['id']} | status={$r['status']} | {$r['created_at']}" . PHP_EOL;
    }
    if (empty($rows)) echo "(no orders yet - OK)" . PHP_EOL;
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "== VERTICAL FEATURE TOGGLES ==" . PHP_EOL;
$rows = $pdo->query("
    SELECT company_id, feature_code, is_enabled
    FROM appcfg.company_feature_toggles
    WHERE company_id IN (1,4,6,7)
    AND feature_code ILIKE '%restaurant%'
    ORDER BY company_id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']} | {$r['feature_code']} | enabled={$r['is_enabled']}" . PHP_EOL;
}
if (empty($rows)) echo "(no restaurant feature toggles found)" . PHP_EOL;

echo PHP_EOL . "== PRIMARY VERTICAL CHECK ==" . PHP_EOL;
$rows = $pdo->query("
    SELECT cv.company_id, c.legal_name, cv.vertical_id, cv.is_primary, cv.status
    FROM appcfg.company_verticals cv
    JOIN core.companies c ON c.id = cv.company_id
    WHERE cv.company_id IN (1,4,6,7)
    ORDER BY cv.company_id, cv.is_primary DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']} | {$r['legal_name']} | vertical={$r['vertical_id']} | primary={$r['is_primary']} | status={$r['status']}" . PHP_EOL;
}
