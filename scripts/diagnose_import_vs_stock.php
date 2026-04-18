<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$companyId = 1;

$batch = DB::table('inventory.product_import_batches')
    ->where('company_id', $companyId)
    ->orderByDesc('id')
    ->first();

if (!$batch) {
    echo "NO_BATCH\n";
    exit(0);
}

echo 'LAST_BATCH_ID=' . (int) $batch->id . PHP_EOL;
echo 'LAST_BATCH_STATUS=' . (string) $batch->status . PHP_EOL;
echo 'LAST_BATCH_TOTAL=' . (int) $batch->total_rows . PHP_EOL;
echo 'LAST_BATCH_CREATED=' . (int) $batch->created_count . PHP_EOL;
echo 'LAST_BATCH_UPDATED=' . (int) $batch->updated_count . PHP_EOL;
echo 'LAST_BATCH_SKIPPED=' . (int) $batch->skipped_count . PHP_EOL;
echo 'LAST_BATCH_ERRORS=' . (int) $batch->error_count . PHP_EOL;

$ledgerImportRows = DB::table('inventory.inventory_ledger')
    ->where('company_id', $companyId)
    ->where('ref_type', 'PRODUCT_IMPORT')
    ->where('ref_id', (int) $batch->id)
    ->count();

echo 'PRODUCT_IMPORT_LEDGER_FOR_LAST_BATCH=' . $ledgerImportRows . PHP_EOL;

$activeProducts = DB::table('inventory.products')
    ->where('company_id', $companyId)
    ->whereNull('deleted_at')
    ->count();

echo 'ACTIVE_PRODUCTS=' . $activeProducts . PHP_EOL;

$currentStockRows = DB::table('inventory.current_stock')
    ->where('company_id', $companyId)
    ->count();

echo 'CURRENT_STOCK_ROWS=' . $currentStockRows . PHP_EOL;

$items = DB::table('inventory.product_import_batch_items')
    ->where('batch_id', (int) $batch->id)
    ->orderBy('row_number')
    ->limit(50)
    ->get(['row_number', 'action_status', 'product_id', 'sku', 'name', 'message']);

foreach ($items as $item) {
    echo sprintf(
        "ROW=%d STATUS=%s PID=%s SKU=%s NAME=%s MSG=%s\n",
        (int) $item->row_number,
        (string) $item->action_status,
        (string) ($item->product_id ?? ''),
        (string) ($item->sku ?? ''),
        (string) ($item->name ?? ''),
        (string) ($item->message ?? '')
    );
}
