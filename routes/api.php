<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('throttle:500,1')->group(function () {
    Route::post('/auth/login', 'Api\\AuthController@login');
    Route::post('/auth/refresh', 'Api\\AuthController@refresh');
});

Route::middleware(['auth.token', 'tenant.rate', 'throttle:6000,1'])->group(function () {
    Route::get('/auth/me', 'Api\\AuthController@me');
    Route::post('/auth/logout', 'Api\\AuthController@logout');

    Route::middleware('rbac.module:APPCFG,view')->group(function () {
        Route::get('/appcfg/modules', 'Api\\AppConfigController@modules');
        Route::get('/appcfg/feature-toggles', 'Api\\AppConfigController@featureToggles');
        Route::get('/appcfg/operational-context', 'Api\\AppConfigController@operationalContext');
        Route::get('/appcfg/home-metrics-summary', 'Api\\AppConfigController@homeMetricsSummary');
        Route::get('/appcfg/operational-limits', 'Api\\AppConfigController@operationalLimits');
        Route::get('/appcfg/commerce-settings', 'Api\\AppConfigController@commerceSettings')->middleware('admin.only');
        Route::get('/appcfg/company-vertical-settings', 'Api\\AppConfigController@companyVerticalSettings');
        Route::get('/appcfg/company-vertical-admin-matrix', 'Api\\AppConfigController@companyVerticalAdminMatrix')->middleware('admin.only');
        Route::get('/appcfg/company-rate-limit-matrix', 'Api\\AppConfigController@companyRateLimitMatrix')->middleware('admin.only');
        Route::get('/appcfg/company-operational-limit-matrix', 'Api\\AppConfigController@companyOperationalLimitMatrix')->middleware('admin.only');
        Route::get('/appcfg/igv-settings', 'Api\\AppConfigController@igvSettings');
        Route::get('/appcfg/company-profile', 'Api\\AppConfigController@companyProfile');
        Route::get('/ops/latency/summary', 'Api\\OpsLatencyController@summary');
        Route::get('/cash/sessions', 'Api\\CashController@sessions');
        Route::get('/cash/sessions/current', 'Api\\CashController@currentSession');
        Route::get('/cash/sessions/{id}/detail', 'Api\\CashController@sessionDetail');
        Route::get('/cash/movements', 'Api\\CashController@movements');
    });

    Route::middleware(['rbac.module:APPCFG,view', 'throttle:1500,1'])->group(function () {
        Route::get('/masters/dashboard', 'Api\\MasterDataController@dashboard');
        Route::get('/masters/options', 'Api\\MasterDataController@options');
        Route::get('/masters/access-control', 'Api\\MasterDataController@accessControl')->middleware('admin.only');
        Route::get('/masters/units', 'Api\\MasterDataController@units');
        Route::get('/masters/warehouses', 'Api\\MasterDataController@warehouses');
        Route::get('/masters/cash-registers', 'Api\\MasterDataController@cashRegisters');
        Route::get('/masters/payment-methods', 'Api\\MasterDataController@paymentMethods');
        Route::get('/masters/series', 'Api\\MasterDataController@series');
        Route::get('/masters/price-tiers', 'Api\\MasterDataController@priceTiers');
        Route::get('/masters/lots', 'Api\\MasterDataController@lots');
        Route::get('/masters/inventory-settings', 'Api\\MasterDataController@inventorySettings');
        Route::get('/masters/document-kinds', 'Api\\MasterDataController@documentKinds');
    });

    Route::middleware('rbac.module:APPCFG,update')->group(function () {
        Route::put('/appcfg/operational-limits', 'Api\\AppConfigController@updateOperationalLimits');
        Route::put('/appcfg/commerce-settings', 'Api\\AppConfigController@updateCommerceSettings')->middleware('admin.only');
        Route::put('/appcfg/company-vertical-settings', 'Api\\AppConfigController@updateCompanyVerticalSettings')->middleware('admin.only');
        Route::put('/appcfg/company-vertical-admin-matrix', 'Api\\AppConfigController@updateCompanyVerticalAdminMatrix')->middleware('admin.only');
        Route::put('/appcfg/company-vertical-admin-matrix/bulk', 'Api\\AppConfigController@updateCompanyVerticalAdminMatrixBulk')->middleware('admin.only');
        Route::put('/appcfg/company-rate-limit-matrix', 'Api\\AppConfigController@updateCompanyRateLimitMatrix')->middleware('admin.only');
        Route::put('/appcfg/company-rate-limit-matrix/bulk', 'Api\\AppConfigController@updateCompanyRateLimitMatrixBulk')->middleware('admin.only');
        Route::put('/appcfg/company-operational-limit-matrix', 'Api\\AppConfigController@updateCompanyOperationalLimitMatrix')->middleware('admin.only');
        Route::put('/appcfg/company-operational-limit-matrix/bulk', 'Api\\AppConfigController@updateCompanyOperationalLimitMatrixBulk')->middleware('admin.only');
        Route::post('/appcfg/admin-companies', 'Api\\AppConfigController@createAdminCompany')->middleware('admin.only');
        Route::post('/appcfg/admin-companies/{id}/reset-admin-password', 'Api\\AppConfigController@resetAdminCompanyPassword')->middleware('admin.only');
        Route::get('/appcfg/company-commerce-admin-matrix', 'Api\\AppConfigController@companyCommerceAdminMatrix')->middleware('admin.only');
        Route::put('/appcfg/company-commerce-admin-matrix', 'Api\\AppConfigController@updateCompanyCommerceAdminMatrix')->middleware('admin.only');
        Route::get('/appcfg/company-inventory-settings-admin-matrix', 'Api\\AppConfigController@companyInventorySettingsAdminMatrix')->middleware('admin.only');
        Route::put('/appcfg/company-inventory-settings-admin-matrix', 'Api\\AppConfigController@updateCompanyInventorySettingsAdminMatrix')->middleware('admin.only');
        Route::put('/appcfg/igv-settings', 'Api\\AppConfigController@updateIgvSettings');
    Route::put('/appcfg/company-profile', 'Api\\AppConfigController@updateCompanyProfile');
    Route::post('/appcfg/company-logo', 'Api\\AppConfigController@uploadCompanyLogo');
    Route::post('/appcfg/company-cert', 'Api\\AppConfigController@uploadCompanyCert');

    Route::post('/cash/sessions', 'Api\\CashController@openSession');
    Route::put('/cash/sessions/{id}/close', 'Api\\CashController@closeSession');
    Route::post('/cash/movements', 'Api\\CashController@createMovement');

        Route::post('/masters/warehouses', 'Api\\MasterDataController@createWarehouse');
        Route::put('/masters/warehouses/{id}', 'Api\\MasterDataController@updateWarehouse');

        Route::post('/masters/cash-registers', 'Api\\MasterDataController@createCashRegister');
        Route::put('/masters/cash-registers/{id}', 'Api\\MasterDataController@updateCashRegister');

        Route::post('/masters/payment-methods', 'Api\\MasterDataController@createPaymentMethod');
        Route::put('/masters/payment-methods/{id}', 'Api\\MasterDataController@updatePaymentMethod');

        Route::post('/masters/series', 'Api\\MasterDataController@createSeries');
        Route::put('/masters/series/{id}', 'Api\\MasterDataController@updateSeries');

        Route::post('/masters/price-tiers', 'Api\\MasterDataController@createPriceTier');
        Route::put('/masters/price-tiers/{id}', 'Api\\MasterDataController@updatePriceTier');

        Route::post('/masters/lots', 'Api\\MasterDataController@createLot');
        Route::post('/masters/document-kinds', 'Api\\MasterDataController@createDocumentKind');
        Route::put('/masters/inventory-settings', 'Api\\MasterDataController@updateInventorySettings');
        Route::put('/masters/document-kinds/{id}', 'Api\\MasterDataController@updateDocumentKind');
        Route::put('/masters/document-kinds', 'Api\\MasterDataController@updateDocumentKinds');
        Route::put('/masters/units', 'Api\\MasterDataController@updateUnits');
        Route::post('/masters/roles', 'Api\\MasterDataController@createRole')->middleware('admin.only');
        Route::put('/masters/roles/{id}', 'Api\\MasterDataController@updateRole')->middleware('admin.only');
        Route::post('/masters/users', 'Api\\MasterDataController@createUser')->middleware('admin.only');
        Route::put('/masters/users/{id}', 'Api\\MasterDataController@updateUser')->middleware('admin.only');
    });

    Route::middleware('rbac.module:SALES,view')->group(function () {
        Route::get('/sales/bootstrap', 'Api\\SalesController@bootstrap');
        Route::get('/sales/lookups', 'Api\\SalesController@lookups');
        Route::get('/sales/price-tiers', 'Api\\SalesController@priceTiers');
        Route::get('/sales/customer-types', 'Api\\SalesController@customerTypes');
        Route::get('/sales/customers', 'Api\\SalesController@customers');
        Route::get('/sales/customers/autocomplete', 'Api\\SalesController@customerAutocomplete');
        Route::get('/sales/customers/resolve-document', 'Api\\SalesController@resolveCustomerByDocument');
        Route::get('/sales/reference-documents', 'Api\\SalesController@referenceDocuments');
        Route::get('/sales/series-numbers', 'Api\\SalesController@seriesNumbers');
        Route::get('/sales/commercial-documents', 'Api\\SalesController@commercialDocuments');
        Route::get('/sales/commercial-documents/export', 'Api\\SalesController@exportCommercialDocuments');
        Route::get('/sales/commercial-documents/{id}', 'Api\\SalesController@showCommercialDocument');
        Route::get('/sales/commercial-documents/{id}/tax-bridge-preview', 'Api\\SalesController@previewTaxBridgePayload');
        Route::get('/sales/commercial-documents/{id}/tax-bridge-debug', 'Api\\SalesController@taxBridgeDebug');
        Route::get('/sales/commercial-documents/{id}/download-xml', 'Api\\SalesController@downloadSunatXml');
        Route::get('/sales/commercial-documents/{id}/download-cdr', 'Api\\SalesController@downloadSunatCdr');
        Route::get('/sales/sunat-exceptions', 'Api\\SunatExceptionsController@index');
        Route::get('/sales/sunat-exceptions/audit', 'Api\\SunatExceptionsController@audit');
        Route::get('/sales/sunat-exceptions/reconcile-stats', 'Api\\SunatExceptionsController@reconcileStats');

        // Daily Summary (Resumen Diario de Boletas)
        Route::get('/sales/daily-summaries', 'Api\\DailySummaryController@index');
        Route::get('/sales/daily-summaries/eligible-documents', 'Api\\DailySummaryController@eligibleDocuments');
        Route::get('/sales/daily-summaries/{id}', 'Api\\DailySummaryController@show');

        // GRE SUNAT (Guia de Remision Electronica)
        Route::get('/sales/gre/lookups', 'Api\\GreGuideController@lookups');
        Route::get('/sales/gre/ubigeos', 'Api\\GreGuideController@ubigeos');
        Route::get('/sales/gre/prefill-document', 'Api\\GreGuideController@prefillFromDocument');
        Route::get('/sales/gre-guides', 'Api\\GreGuideController@index');
        Route::get('/sales/gre-guides/{id}', 'Api\\GreGuideController@show');
        Route::get('/sales/gre-guides/{id}/tax-bridge-audit', 'Api\\GreGuideController@taxBridgeAuditHistory');
        Route::get('/sales/gre-guides/{id}/print', 'Api\\GreGuideController@printable');

        // Restaurant operations (comandas & orders)
        Route::get('/restaurant/comandas', 'Api\\RestaurantController@comandas');
            // Tax Bridge Audit Logs (Trazabilidad de envíos tributarios)
            Route::get('/tax-bridge/audit/document/{documentId}', 'Api\\TaxBridgeAuditController@getDocumentHistory');
            Route::get('/tax-bridge/audit/branch', 'Api\\TaxBridgeAuditController@getBranchHistory');
            Route::get('/tax-bridge/audit/statistics', 'Api\\TaxBridgeAuditController@getStatistics');
            Route::get('/tax-bridge/audit/failures', 'Api\\TaxBridgeAuditController@getRecentFailures');
            Route::get('/tax-bridge/audit/{logId}', 'Api\\TaxBridgeAuditController@getLogDetails');
        Route::get('/restaurant/tables', 'Api\\RestaurantController@tables');
        Route::get('/restaurant/orders', 'Api\\RestaurantController@fetchOrders');
    });

    Route::middleware('rbac.module:SALES,create')->group(function () {
        Route::post('/sales/commercial-documents', 'Api\\SalesController@createCommercialDocument');
        Route::post('/sales/commercial-documents/{id}/convert', 'Api\\SalesController@convertCommercialDocument');
        Route::put('/sales/commercial-documents/{id}', 'Api\\SalesController@updateCommercialDocument');
        Route::post('/sales/commercial-documents/{id}/void', 'Api\\SalesController@voidCommercialDocument');
        Route::put('/sales/commercial-documents/{id}/retry-tax-bridge', 'Api\\SalesController@retryTaxBridgeSend');
        Route::put('/sales/commercial-documents/{id}/sunat-void', 'Api\\SalesController@sunatVoidCommunication');
        Route::post('/sales/sunat-exceptions/{id}/manual-confirm', 'Api\\SunatExceptionsController@manualConfirm');
        Route::post('/sales/customers', 'Api\\SalesController@createCustomer');
        Route::put('/sales/customers/{id}', 'Api\\SalesController@updateCustomer');

        // Daily Summary (Resumen Diario de Boletas)
        Route::post('/sales/daily-summaries', 'Api\\DailySummaryController@store');
        Route::delete('/sales/daily-summaries/{id}', 'Api\\DailySummaryController@destroy');
        Route::delete('/sales/daily-summaries/{id}/documents/{documentId}', 'Api\\DailySummaryController@removeDocument');
        Route::put('/sales/daily-summaries/{id}/send', 'Api\\DailySummaryController@send');

        Route::post('/sales/gre-guides', 'Api\\GreGuideController@store');
        Route::put('/sales/gre-guides/{id}', 'Api\\GreGuideController@update');
        Route::put('/sales/gre-guides/{id}/send', 'Api\\GreGuideController@send');
        Route::put('/sales/gre-guides/{id}/status-ticket', 'Api\\GreGuideController@statusTicket');
        Route::put('/sales/gre-guides/{id}/cancel', 'Api\\GreGuideController@cancel');

        Route::put('/restaurant/comandas/{id}/status', 'Api\\RestaurantController@updateComandaStatus');
        Route::post('/restaurant/orders', 'Api\\RestaurantController@createOrder');
        Route::post('/restaurant/orders/{id}/checkout', 'Api\\RestaurantController@checkoutOrder');
        Route::post('/restaurant/tables', 'Api\\RestaurantController@createTable');
        Route::put('/restaurant/tables/{id}', 'Api\\RestaurantController@updateTable');
    });

    Route::middleware('rbac.module:INVENTORY,view')->group(function () {
        Route::get('/purchases/lookups', 'Api\\PurchasesController@lookups');
        Route::get('/purchases/list', 'Api\\PurchasesController@listStockEntries');
        Route::get('/purchases/export', 'Api\\PurchasesController@exportStockEntries');
        Route::get('/purchases/suppliers/autocomplete', 'Api\\PurchasesController@supplierAutocomplete');
        Route::get('/purchases/suppliers/resolve-document', 'Api\\PurchasesController@resolveSupplierByDocument');
    });

    Route::middleware('rbac.module:INVENTORY,view')->group(function () {
        Route::get('/inventory/product-lookups', 'Api\\InventoryController@productLookups');
        Route::get('/inventory/products', 'Api\\InventoryController@products');
        Route::get('/inventory/products/import-batches', 'Api\\InventoryController@productImportBatches');
        Route::get('/inventory/products/import-batches/{batchId}', 'Api\\InventoryController@productImportBatchDetail');
        Route::get('/inventory/products/{id}/commercial-config', 'Api\\InventoryController@productCommercialConfig');
        Route::get('/inventory/product-masters', 'Api\\InventoryController@productMasters');
        Route::post('/inventory/products', 'Api\\InventoryController@createProduct');
        Route::post('/inventory/products/bulk-import', 'Api\\InventoryController@bulkImportProducts');
        Route::put('/inventory/products/{id}', 'Api\\InventoryController@updateProduct');
        Route::put('/inventory/products/{id}/commercial-config', 'Api\\InventoryController@updateProductCommercialConfig');
        Route::get('/inventory/current-stock', 'Api\\InventoryController@currentStock');
        Route::get('/inventory/lots', 'Api\\InventoryController@lots');
        Route::get('/inventory/stock-entries', 'Api\\InventoryController@stockEntries');
        Route::get('/inventory/kardex', 'Api\\InventoryController@kardex');
    });

    Route::middleware('rbac.module:INVENTORY,update')->group(function () {
        Route::post('/inventory/stock-entries', 'Api\\InventoryController@createStockEntry');
        Route::post('/purchases/orders/{id}/receive', 'Api\\PurchasesController@receivePurchaseOrder');
    });

    Route::middleware(['rbac.module:INVENTORY,update', 'rbac.module:INVENTORY,approve'])->group(function () {
        Route::put('/purchases/stock-entries/{id}', 'Api\\PurchasesController@updateStockEntry');
    });

    Route::middleware('rbac.module:INVENTORY,create')->group(function () {
        Route::post('/inventory/product-masters', 'Api\\InventoryController@createProductMaster');
        Route::put('/inventory/product-masters/{id}', 'Api\\InventoryController@updateProductMaster');
    });

    Route::middleware(['rbac.module:INVENTORY,view', 'throttle:1200,1'])->prefix('inventory-pro')->group(function () {
        Route::get('/dashboard', 'Api\\InventoryReportsController@dashboard');
        Route::get('/report-requests', 'Api\\InventoryReportsController@listRequests');
        Route::get('/report-requests/{id}', 'Api\\InventoryReportsController@showRequest');
        Route::get('/daily-snapshot', 'Api\\InventoryReportsController@dailySnapshot');
        Route::get('/lot-expiry', 'Api\\InventoryReportsController@lotExpiry');
    });

    Route::middleware(['rbac.module:INVENTORY,update', 'throttle:500,1'])->prefix('inventory-pro')->group(function () {
        Route::post('/report-requests', 'Api\\InventoryReportsController@createRequest');
    });

    // Generic Reports API (extensible by modules)
    Route::middleware(['rbac.module:INVENTORY,view', 'throttle:1200,1'])->prefix('reports')->group(function () {
        Route::get('/catalog', 'Api\\ReportsController@catalog');
        Route::get('/requests', 'Api\\ReportsController@index');
        Route::get('/requests/{id}', 'Api\\ReportsController@show');
    });

    Route::middleware(['rbac.module:INVENTORY,update', 'throttle:500,1'])->prefix('reports')->group(function () {
        Route::post('/requests', 'Api\\ReportsController@store');
    });
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'facturacion-backend',
        'framework' => app()->version(),
        'time' => now()->toIso8601String(),
    ]);
});
