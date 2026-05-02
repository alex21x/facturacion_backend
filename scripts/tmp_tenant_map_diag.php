<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = DB::connection()->getPdo();

$cols = $pdo->query("SELECT table_schema, table_name, column_name FROM information_schema.columns WHERE column_name ILIKE '%tenant%' OR column_name ILIKE '%slug%' OR column_name ILIKE '%hash%' OR column_name ILIKE '%public_id%' ORDER BY table_schema, table_name, column_name")->fetchAll(PDO::FETCH_ASSOC);
echo "== CANDIDATE COLUMNS ==\n";
foreach ($cols as $c) {
    echo $c['table_schema'] . '.' . $c['table_name'] . '.' . $c['column_name'] . "\n";
}

echo "\n== core.companies sample ==\n";
$sample = $pdo->query("SELECT * FROM core.companies LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($sample as $row) {
    echo json_encode($row) . "\n";
}

$tenant = 'emp-f42ac054a9a4';
echo "\n== SEARCH TOKEN: $tenant ==\n";
foreach ($cols as $c) {
    $schema = $c['table_schema'];
    $table = $c['table_name'];
    $col = $c['column_name'];
    $sql = "SELECT COUNT(*) AS c FROM \"$schema\".\"$table\" WHERE CAST(\"$col\" AS TEXT) = :token";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token' => $tenant]);
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        echo "$schema.$table.$col => $count\n";
        $rows = $pdo->prepare("SELECT * FROM \"$schema\".\"$table\" WHERE CAST(\"$col\" AS TEXT) = :token LIMIT 5");
        $rows->execute([':token' => $tenant]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo json_encode($r) . "\n";
        }
    }
}
