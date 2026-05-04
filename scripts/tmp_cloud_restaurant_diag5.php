<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();

echo "== auth.users columns ==" . PHP_EOL;
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='auth' AND table_name='users' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . PHP_EOL;

echo PHP_EOL . "== auth.users for companies 1,4,6,7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT id, company_id, branch_id, username, email, status FROM auth.users WHERE company_id IN (1,4,6,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "{$r['company_id']}|id={$r['id']}|user={$r['username']}|branch={$r['branch_id']}|status={$r['status']}" . PHP_EOL;
}

echo PHP_EOL . "== sales.series_numbers for company 7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM sales.series_numbers WHERE company_id = 7")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
if (empty($rows)) echo "(NONE - company 7 cannot create orders!)" . PHP_EOL;

echo PHP_EOL . "== restaurant.tables detail ==" . PHP_EOL;
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='restaurant' AND table_name='tables' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo "columns: " . implode(', ', $cols) . PHP_EOL;
$rows = $pdo->query("SELECT * FROM restaurant.tables WHERE company_id IN (1,4,6,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
