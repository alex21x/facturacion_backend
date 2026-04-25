<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\AppConfigController;

$request = Request::create('/api/appcfg/company-commerce-admin-matrix', 'PUT', [
    'company_id' => 4,
    'features' => [
        'SALES_TAX_BRIDGE' => true,
        'SALES_ANTICIPO_ENABLED' => true,
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' => true,
        'SALES_TAX_BRIDGE_DEBUG_VIEW' => true,
    ],
]);
$request->attributes->set('auth_user', (object) ['id' => 1]);

$controller = app(AppConfigController::class);
$response = $controller->updateCompanyCommerceAdminMatrix($request);

$rows = DB::table('appcfg.company_feature_toggles')
    ->where('company_id', 4)
    ->whereIn('feature_code', [
        'SALES_TAX_BRIDGE',
        'SALES_ANTICIPO_ENABLED',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
        'SALES_TAX_BRIDGE_DEBUG_VIEW',
    ])
    ->select('feature_code', 'is_enabled')
    ->orderBy('feature_code')
    ->get();

echo 'RESP_STATUS=' . $response->status() . PHP_EOL;
echo 'ROWS=' . json_encode($rows, JSON_UNESCAPED_UNICODE) . PHP_EOL;
