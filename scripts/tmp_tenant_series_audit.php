<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = DB::connection()->getPdo();
$slug = 'emp-f42ac054a9a4';

echo "== ACCESS LINK ==\n";
$stmt = $pdo->prepare("SELECT * FROM appcfg.company_access_links WHERE access_slug = :slug");
$stmt->execute([':slug' => $slug]);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($links as $row) {
    echo json_encode($row) . "\n";
}
if (!$links) {
    echo "(NO ACCESS LINK FOUND)\n";
    exit(0);
}

$companyId = (int) $links[0]['company_id'];
echo "\n== COMPANY ==\n";
$stmt = $pdo->prepare("SELECT * FROM core.companies WHERE id = :id");
$stmt->execute([':id' => $companyId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== USERS ==\n";
$stmt = $pdo->prepare("SELECT id, username, branch_id, status FROM auth.users WHERE company_id = :id ORDER BY id");
$stmt->execute([':id' => $companyId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== BRANCHES ==\n";
$stmt = $pdo->prepare("SELECT id, company_id, name, status FROM core.branches WHERE company_id = :id ORDER BY id");
$stmt->execute([':id' => $companyId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== WAREHOUSES ==\n";
$stmt = $pdo->prepare("SELECT id, company_id, branch_id, code, name, status FROM inventory.warehouses WHERE company_id = :id ORDER BY id");
$stmt->execute([':id' => $companyId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== RESTAURANT TABLES ==\n";
$stmt = $pdo->prepare("SELECT id, company_id, branch_id, code, name, status FROM restaurant.tables WHERE company_id = :id ORDER BY id");
$stmt->execute([':id' => $companyId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== SERIES NUMBERS ==\n";
$stmt = $pdo->prepare("SELECT id, company_id, branch_id, warehouse_id, document_kind_id, document_kind, series, current_number, is_enabled FROM sales.series_numbers WHERE company_id = :id ORDER BY branch_id NULLS FIRST, warehouse_id NULLS FIRST, id");
$stmt->execute([':id' => $companyId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== DOCUMENT KINDS ==\n";
$stmt = $pdo->query("SELECT id, code, label, is_enabled FROM sales.document_kinds ORDER BY id");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}
