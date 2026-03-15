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

Route::middleware('throttle:30,1')->group(function () {
    Route::post('/auth/login', 'Api\\AuthController@login');
    Route::post('/auth/refresh', 'Api\\AuthController@refresh');
});

Route::middleware('auth.token')->group(function () {
    Route::get('/auth/me', 'Api\\AuthController@me');
    Route::post('/auth/logout', 'Api\\AuthController@logout');

    Route::middleware('rbac.module:APPCFG,view')->group(function () {
        Route::get('/appcfg/modules', 'Api\\AppConfigController@modules');
        Route::get('/appcfg/feature-toggles', 'Api\\AppConfigController@featureToggles');
        Route::get('/appcfg/operational-context', 'Api\\AppConfigController@operationalContext');
        Route::get('/appcfg/operational-limits', 'Api\\AppConfigController@operationalLimits');
        Route::get('/appcfg/commerce-settings', 'Api\\AppConfigController@commerceSettings');
        Route::get('/appcfg/company-profile', 'Api\\AppConfigController@companyProfile');
        Route::get('/cash/sessions', 'Api\\CashController@sessions');
        Route::get('/cash/sessions/current', 'Api\\CashController@currentSession');
        Route::get('/cash/movements', 'Api\\CashController@movements');
    });

    Route::middleware(['rbac.module:APPCFG,view', 'throttle:240,1'])->group(function () {
        Route::get('/masters/dashboard', 'Api\\MasterDataController@dashboard');
        Route::get('/masters/options', 'Api\\MasterDataController@options');
        Route::get('/masters/units', 'Api\\MasterDataController@units');
        Route::get('/masters/warehouses', 'Api\\MasterDataController@warehouses');
        Route::get('/masters/cash-registers', 'Api\\MasterDataController@cashRegisters');
        Route::get('/masters/payment-methods', 'Api\\MasterDataController@paymentMethods');
        Route::get('/masters/series', 'Api\\MasterDataController@series');
        Route::get('/masters/lots', 'Api\\MasterDataController@lots');
        Route::get('/masters/inventory-settings', 'Api\\MasterDataController@inventorySettings');
        Route::get('/masters/document-kinds', 'Api\\MasterDataController@documentKinds');
    });

    Route::middleware('rbac.module:APPCFG,update')->group(function () {
        Route::put('/appcfg/operational-limits', 'Api\\AppConfigController@updateOperationalLimits');
        Route::put('/appcfg/commerce-settings', 'Api\\AppConfigController@updateCommerceSettings');
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

        Route::post('/masters/lots', 'Api\\MasterDataController@createLot');
        Route::put('/masters/inventory-settings', 'Api\\MasterDataController@updateInventorySettings');
        Route::put('/masters/document-kinds', 'Api\\MasterDataController@updateDocumentKinds');
        Route::put('/masters/units', 'Api\\MasterDataController@updateUnits');
    });

    Route::middleware('rbac.module:SALES,view')->group(function () {
        Route::get('/sales/lookups', 'Api\\SalesController@lookups');
        Route::get('/sales/customers', 'Api\\SalesController@customers');
        Route::get('/sales/customers/autocomplete', 'Api\\SalesController@customerAutocomplete');
        Route::get('/sales/series-numbers', 'Api\\SalesController@seriesNumbers');
        Route::get('/sales/commercial-documents', 'Api\\SalesController@commercialDocuments');
    });

    Route::middleware('rbac.module:SALES,create')->group(function () {
        Route::post('/sales/commercial-documents', 'Api\\SalesController@createCommercialDocument');
        Route::post('/sales/customers', 'Api\\SalesController@createCustomer');
        Route::put('/sales/customers/{id}', 'Api\\SalesController@updateCustomer');
    });

    Route::middleware('rbac.module:INVENTORY,view')->group(function () {
        Route::get('/inventory/product-lookups', 'Api\\InventoryController@productLookups');
        Route::get('/inventory/products', 'Api\\InventoryController@products');
        Route::get('/inventory/products/{id}/commercial-config', 'Api\\InventoryController@productCommercialConfig');
        Route::get('/inventory/current-stock', 'Api\\InventoryController@currentStock');
        Route::get('/inventory/lots', 'Api\\InventoryController@lots');
        Route::get('/inventory/stock-entries', 'Api\\InventoryController@stockEntries');
        Route::get('/inventory/kardex', 'Api\\InventoryController@kardex');
    });

    Route::middleware('rbac.module:INVENTORY,update')->group(function () {
        Route::post('/inventory/products', 'Api\\InventoryController@createProduct');
        Route::put('/inventory/products/{id}', 'Api\\InventoryController@updateProduct');
        Route::put('/inventory/products/{id}/commercial-config', 'Api\\InventoryController@updateProductCommercialConfig');
        Route::post('/inventory/stock-entries', 'Api\\InventoryController@createStockEntry');
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
