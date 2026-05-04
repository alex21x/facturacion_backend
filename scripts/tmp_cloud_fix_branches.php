<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();

echo "== FIXING branch_id in restaurant.tables ==" . PHP_EOL;

// Company 1 (MSEP PERU SAC): user admin has branch_id=1, tables id=4,5 have branch_id=4 (wrong)
$affected = $pdo->exec("UPDATE restaurant.tables SET branch_id=1 WHERE company_id=1 AND code IN ('M01','M02')");
echo "Company 1 tables updated: $affected rows" . PHP_EOL;

// Company 6 (LUYO): user luyo has branch_id=6, tables id=6,7 have branch_id=4 (wrong)
$affected = $pdo->exec("UPDATE restaurant.tables SET branch_id=6 WHERE company_id=6");
echo "Company 6 tables updated: $affected rows" . PHP_EOL;

// Company 7 (JOSSELIN): user admin_joss has branch_id=7, tables id=8,9 have branch_id=4 (wrong)
$affected = $pdo->exec("UPDATE restaurant.tables SET branch_id=7 WHERE company_id=7");
echo "Company 7 tables updated: $affected rows" . PHP_EOL;

echo PHP_EOL . "== Checking series_numbers for companies 6 and 7 ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM sales.series_numbers WHERE company_id IN (6,7) ORDER BY company_id, document_kind")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
if (empty($rows)) echo "(None for 6 and 7)" . PHP_EOL;

// Get series_numbers table columns
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='sales' AND table_name='series_numbers' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
echo "series_numbers columns: " . implode(', ', $cols) . PHP_EOL;

// Check if company 6 has SALES_ORDER series
$c6SalesOrder = $pdo->query("SELECT COUNT(*) FROM sales.series_numbers WHERE company_id=6 AND document_kind='SALES_ORDER'")->fetchColumn();
echo "Company 6 SALES_ORDER series count: $c6SalesOrder" . PHP_EOL;

// Check if company 7 has SALES_ORDER series
$c7SalesOrder = $pdo->query("SELECT COUNT(*) FROM sales.series_numbers WHERE company_id=7 AND document_kind='SALES_ORDER'")->fetchColumn();
echo "Company 7 SALES_ORDER series count: $c7SalesOrder" . PHP_EOL;

// Get a sample of company 4's series_numbers to understand the schema
$sampleC4 = $pdo->query("SELECT * FROM sales.series_numbers WHERE company_id=4 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL . "Company 4 series sample: " . PHP_EOL;
foreach ($sampleC4 as $r) { echo json_encode($r) . PHP_EOL; }

echo PHP_EOL . "== Adding SALES_ORDER series for companies missing it ==" . PHP_EOL;

// Add SALES_ORDER series for company 6 if missing
if ((int)$c6SalesOrder === 0) {
    // Get branch for company 6
    $branch6 = $pdo->query("SELECT id FROM core.branches WHERE company_id=6 LIMIT 1")->fetchColumn();
    $sampleRow = $pdo->query("SELECT * FROM sales.series_numbers WHERE company_id=4 AND document_kind='SALES_ORDER' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($sampleRow && $branch6) {
        // Build insert with only existing columns
        $hasWarehouse = in_array('warehouse_id', $cols);
        $hasBranch = in_array('branch_id', $cols);
        $hasEnabled = in_array('is_enabled', $cols);
        
        $insertCols = ['company_id', 'document_kind', 'series', 'current_number'];
        $insertVals = [6, 'SALES_ORDER', 'PV01', 1];
        
        if ($hasBranch) { $insertCols[] = 'branch_id'; $insertVals[] = $branch6; }
        if ($hasWarehouse) { $insertCols[] = 'warehouse_id'; $insertVals[] = 'NULL'; }
        if ($hasEnabled) { $insertCols[] = 'is_enabled'; $insertVals[] = 'true'; }
        
        $colsStr = implode(', ', $insertCols);
        $placeholders = array_map(fn($i, $v) => is_string($v) && $v === 'NULL' ? 'NULL' : ($v === 'true' ? 'true' : '?'), array_keys($insertVals), $insertVals);
        $filteredVals = array_filter(array_map(fn($v) => ($v !== 'NULL' && $v !== 'true') ? $v : null, $insertVals), fn($v) => $v !== null);
        
        // Simple approach
        try {
            $pdo->exec("INSERT INTO sales.series_numbers (company_id, document_kind, series, current_number" . ($hasBranch ? ", branch_id" : "") . ($hasEnabled ? ", is_enabled" : "") . ") VALUES (6, 'SALES_ORDER', 'PV01', 1" . ($hasBranch ? ", $branch6" : "") . ($hasEnabled ? ", true" : "") . ")");
            echo "Company 6: SALES_ORDER series PV01 created" . PHP_EOL;
        } catch (Exception $e) {
            echo "Company 6 series insert error: " . $e->getMessage() . PHP_EOL;
        }
    }
}

// Add SALES_ORDER series for company 7 if missing
if ((int)$c7SalesOrder === 0) {
    $branch7 = $pdo->query("SELECT id FROM core.branches WHERE company_id=7 LIMIT 1")->fetchColumn();
    $hasBranch = in_array('branch_id', $cols);
    $hasEnabled = in_array('is_enabled', $cols);
    
    try {
        $pdo->exec("INSERT INTO sales.series_numbers (company_id, document_kind, series, current_number" . ($hasBranch ? ", branch_id" : "") . ($hasEnabled ? ", is_enabled" : "") . ") VALUES (7, 'SALES_ORDER', 'PV01', 1" . ($hasBranch ? ", $branch7" : "") . ($hasEnabled ? ", true" : "") . ")");
        echo "Company 7: SALES_ORDER series PV01 created" . PHP_EOL;
    } catch (Exception $e) {
        echo "Company 7 series insert error: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "== VERIFICATION ==" . PHP_EOL;
$rows = $pdo->query("SELECT * FROM restaurant.tables WHERE company_id IN (1,4,6,7) ORDER BY company_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo "company={$r['company_id']}|branch={$r['branch_id']}|name={$r['name']}|status={$r['status']}" . PHP_EOL; }

echo PHP_EOL;
$rows = $pdo->query("SELECT company_id, document_kind, series, is_enabled FROM sales.series_numbers WHERE company_id IN (6,7)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo json_encode($r) . PHP_EOL; }
