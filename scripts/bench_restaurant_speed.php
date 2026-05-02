<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var App\Services\Restaurant\RestaurantOrderService $orderService */
$orderService = $app->make(App\Services\Restaurant\RestaurantOrderService::class);
/** @var App\Services\Restaurant\RestaurantComandaGateway $gateway */
$gateway = $app->make(App\Services\Restaurant\RestaurantComandaGateway::class);

$companyId = 1;
$branchId = 1;
$runs = 7;

$orders = [];
$tables = [];
$comandas = [];

for ($i = 0; $i < $runs; $i++) {
    $t = microtime(true);
    $orderService->fetchOrders($companyId, $branchId, '', '', 1, 12, false, false);
    $orders[] = (microtime(true) - $t) * 1000;

    $t = microtime(true);
    $gateway->listTables($companyId, $branchId, '', '', null);
    $tables[] = (microtime(true) - $t) * 1000;

    $t = microtime(true);
    $gateway->list($companyId, $branchId, '', '', 1, 20);
    $comandas[] = (microtime(true) - $t) * 1000;
}

$avg = static function (array $values): float {
    return array_sum($values) / max(1, count($values));
};

$round = static function (array $values): array {
    return array_map(static fn (float $v): float => round($v, 2), $values);
};

echo 'orders_avg_ms=' . round($avg($orders), 2) . PHP_EOL;
echo 'tables_avg_ms=' . round($avg($tables), 2) . PHP_EOL;
echo 'comandas_avg_ms=' . round($avg($comandas), 2) . PHP_EOL;
echo 'orders_runs=' . json_encode($round($orders)) . PHP_EOL;
echo 'tables_runs=' . json_encode($round($tables)) . PHP_EOL;
echo 'comandas_runs=' . json_encode($round($comandas)) . PHP_EOL;
