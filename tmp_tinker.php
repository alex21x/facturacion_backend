<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'core.companies'              => 'id',
    'sales.commercial_documents'  => 'company_id',
    'sales.sales_orders'          => 'company_id',
    'sales.customers'             => 'company_id',
    'inventory.products'          => 'company_id',
    'inventory.stock_entries'     => 'company_id',
    'restaurant.tables'           => 'company_id',
    'sales.cash_sessions'         => 'company_id',
    'core.branches'               => 'company_id',
];

foreach ($tables as $table => $col) {
    $rows = DB::select("SELECT {$col} as cid, count(*) as n FROM {$table} GROUP BY {$col} ORDER BY {$col}");
    if (empty($rows)) {
        echo "{$table}: (empty)\n";
    } else {
        foreach ($rows as $r) {
            echo "{$table} [company={$r->cid}]: {$r->n}\n";
        }
    }
}
