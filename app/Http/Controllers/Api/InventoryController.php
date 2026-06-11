<?php

namespace App\Http\Controllers\Api;

use App\Application\Commands\Inventory\CreateInventoryStockEntryCommand;
use App\Application\Commands\Inventory\UpdateInventoryProductCommercialConfigCommand;
use App\Application\UseCases\Inventory\CreateInventoryStockEntryUseCase;
use App\Application\UseCases\Inventory\GetCurrentStockUseCase;
use App\Application\UseCases\Inventory\GetInventoryKardexUseCase;
use App\Application\UseCases\Inventory\GetInventoryLotsUseCase;
use App\Application\UseCases\Inventory\GetInventoryProductCommercialConfigUseCase;
use App\Application\UseCases\Inventory\GetProductLookupsUseCase;
use App\Application\UseCases\Inventory\GetInventoryStockEntriesUseCase;
use App\Application\UseCases\Inventory\UpdateInventoryProductCommercialConfigUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BulkImportProductsRequest;
use App\Http\Requests\Inventory\BulkUpdateProductStockRequest;
use App\Http\Requests\Inventory\StoreProductMasterRequest;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\StoreStockEntryRequest;
use App\Http\Requests\Inventory\UpdateProductCommercialConfigRequest;
use App\Http\Requests\Inventory\UpdateProductMasterRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Services\Authorization\CompanyFeatureAuthorizationService;
use App\Services\Inventory\InventoryControllerSupportService;
use App\Services\Inventory\InventoryProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class InventoryController extends Controller
{
    private const FEATURE_PRODUCTS_BY_PROFILE = 'INVENTORY_PRODUCTS_BY_PROFILE';
    private const FEATURE_PRODUCT_MASTERS_BY_PROFILE = 'INVENTORY_PRODUCT_MASTERS_BY_PROFILE';
    private const LOOKUPS_CACHE_TTL_SECONDS = 60;
    private const PRODUCT_COMMERCIAL_CONFIG_CACHE_TTL_SECONDS = 30;

    public function __construct(
        private GetProductLookupsUseCase $getProductLookupsUseCase,
        private CreateInventoryStockEntryUseCase $createInventoryStockEntryUseCase,
        private GetCurrentStockUseCase $getCurrentStockUseCase,
        private GetInventoryLotsUseCase $getInventoryLotsUseCase,
        private GetInventoryStockEntriesUseCase $getInventoryStockEntriesUseCase,
        private GetInventoryKardexUseCase $getInventoryKardexUseCase,
        private GetInventoryProductCommercialConfigUseCase $getInventoryProductCommercialConfigUseCase,
        private UpdateInventoryProductCommercialConfigUseCase $updateInventoryProductCommercialConfigUseCase,
        private CompanyFeatureAuthorizationService $companyFeatureAuthorizationService,
        private InventoryControllerSupportService $inventoryControllerSupportService,
        private InventoryProductService $inventoryProductService
    )
    {
    }

    public function productLookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $userId = (int) ($authUser->id ?? 0);

        $cacheKey = sprintf('inventory:product_lookups:%d:%d', $companyId, $userId);

        $payload = Cache::remember($cacheKey, self::LOOKUPS_CACHE_TTL_SECONDS, function () use ($authUser, $companyId) {
            $lookups = $this->getProductLookupsUseCase->execute($companyId);

            return [
                'units' => $lookups['units'],
                'categories' => $lookups['categories'],
                'lines' => $lookups['lines'],
                'brands' => $lookups['brands'],
                'locations' => $lookups['locations'],
                'warranties' => $lookups['warranties'],
                'product_natures' => [
                    ['code' => 'PRODUCT', 'label' => 'Producto'],
                    ['code' => 'SUPPLY', 'label' => 'Insumo'],
                ],
                'permissions' => [
                    'can_manage_products' => $this->canManageProducts($authUser, $companyId),
                    'can_manage_product_masters' => $this->canManageProductMasters($authUser, $companyId),
                ],
            ];
        });

        return response()->json($payload);
    }

    public function products(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $limit = (int) $request->query('limit', 50000);
        $autocomplete = filter_var($request->query('autocomplete', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'data' => $this->inventoryProductService->listProducts($companyId, $search, $status, $limit, $autocomplete),
        ]);
    }

    public function createProduct(StoreProductRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para guardar productos.'], 403);
        }

        $result = $this->inventoryProductService->createProduct(
            $companyId,
            $request->validated(),
            (int) ($authUser->id ?? 0)
        );
        if (!$result['ok']) {
            $payload = ['message' => $result['message']];
            if (isset($result['duplicate_product_id'])) {
                $payload['duplicate_product_id'] = $result['duplicate_product_id'];
            }

            return response()->json($payload, (int) $result['status']);
        }

        return response()->json([
            'message' => $result['message'],
            'id' => (int) $result['id'],
        ], (int) $result['status']);
    }

    public function updateProduct(UpdateProductRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para guardar productos.'], 403);
        }

        $result = $this->inventoryProductService->updateProduct(
            $companyId,
            $id,
            $request->validated(),
            (int) ($authUser->id ?? 0)
        );
        if (!$result['ok']) {
            $payload = ['message' => $result['message']];
            if (isset($result['duplicate_product_id'])) {
                $payload['duplicate_product_id'] = $result['duplicate_product_id'];
            }

            return response()->json($payload, (int) $result['status']);
        }

        return response()->json(['message' => $result['message']], (int) $result['status']);
    }

    public function bulkImportProducts(BulkImportProductsRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para importar productos.'], 403);
        }

        $result = $this->inventoryProductService->bulkImportProducts($companyId, (int) $authUser->id, $request->validated());
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function bulkUpdateProductStock(BulkUpdateProductStockRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para actualizar stock.'], 403);
        }

        $result = $this->inventoryProductService->bulkUpdateProductStock($companyId, (int) $authUser->id, $request->validated());
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function productImportBatches(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $limit = (int) $request->query('limit', 30);

        $result = $this->inventoryProductService->listImportBatches($companyId, $limit);

        return response()->json([
            'data' => $result['data'],
        ], (int) $result['status']);
    }

    public function productImportBatchDetail(Request $request, int $batchId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $itemsLimit = (int) $request->query('items_limit', 500);

        $result = $this->inventoryProductService->getImportBatchDetail($companyId, $batchId, $itemsLimit);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function currentStock(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $warehouseId = $request->query('warehouse_id');
        $productId = $request->query('product_id');
        $productIdsParam = trim((string) $request->query('product_ids', ''));
        $productIds = [];

        if ($productIdsParam !== '') {
            $rawIds = preg_split('/\s*,\s*/', $productIdsParam);
            if (is_array($rawIds)) {
                foreach ($rawIds as $rawId) {
                    $id = (int) $rawId;
                    if ($id > 0) {
                        $productIds[$id] = $id;
                    }
                }
            }
        }

        return response()->json([
            'data' => $this->getCurrentStockUseCase->execute($companyId, $warehouseId, $productId, array_values($productIds)),
        ]);
    }

    public function lots(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $warehouseId = $request->query('warehouse_id');
        $productId = $request->query('product_id');
        $onlyWithStock = filter_var($request->query('only_with_stock', true), FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'data' => $this->getInventoryLotsUseCase->execute($companyId, $warehouseId, $productId, $onlyWithStock),
        ]);
    }

    public function stockEntries(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $warehouseId = $request->query('warehouse_id');
        $entryType = $request->query('entry_type');
        $limit = (int) $request->query('limit', 80);

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 300) {
            $limit = 300;
        }

        return response()->json([
            'data' => $this->getInventoryStockEntriesUseCase->execute($companyId, $warehouseId, $entryType, $limit),
        ]);
    }

    public function kardex(Request $request)
    {
        $companyId   = (int) $request->attributes->get('resolved_company_id');
        $productId   = $request->query('product_id');
        $warehouseId = $request->query('warehouse_id');
        $dateFrom    = $request->query('date_from');
        $dateTo      = $request->query('date_to');
        $perPage     = min(max((int) $request->query('per_page', 50), 1), 200);
        $page        = max((int) $request->query('page', 1), 1);

        $result = $this->getInventoryKardexUseCase->execute($companyId, $productId, $warehouseId, $dateFrom, $dateTo, $perPage, $page);

        return response()->json($result);
    }

    public function createStockEntry(StoreStockEntryRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = array_key_exists('branch_id', $payload) ? $payload['branch_id'] : $authUser->branch_id;
        $warehouseId = (int) $payload['warehouse_id'];

        try {
            $result = $this->createInventoryStockEntryUseCase->execute(
                CreateInventoryStockEntryCommand::fromInput(
                    $authUser,
                    $payload,
                    $companyId,
                    $branchId,
                    $warehouseId
                )
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Stock entry created',
            'data' => $result,
        ], 201);
    }

    public function productCommercialConfig(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $cacheKey = sprintf('inventory:product_commercial_config:%d:%d', $companyId, $id);

        $config = Cache::remember($cacheKey, self::PRODUCT_COMMERCIAL_CONFIG_CACHE_TTL_SECONDS, function () use ($companyId, $id) {
            return $this->getInventoryProductCommercialConfigUseCase->execute($companyId, $id);
        });
        if ($config === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($config);
    }

    public function updateProductCommercialConfig(UpdateProductCommercialConfigRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para guardar productos.'], 403);
        }

        $payload = $request->validated();
        try {
            $this->updateInventoryProductCommercialConfigUseCase->execute(
                UpdateInventoryProductCommercialConfigCommand::fromInput(
                    $authUser,
                    $companyId,
                    $id,
                    $payload
                )
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Product not found') {
                return response()->json(['message' => 'Product not found'], 404);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget(sprintf('inventory:product_commercial_config:%d:%d', $companyId, $id));

        $config = $this->getInventoryProductCommercialConfigUseCase->execute($companyId, $id);
        if ($config === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($config);
    }

    public function productMasters(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        return response()->json($this->inventoryProductService->listProductMasters($companyId));
    }

    public function createProductMaster(StoreProductMasterRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->canManageProductMasters($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para gestionar maestros de producto.'], 403);
        }

        $payload = $request->validated();
        $result = $this->inventoryProductService->createProductMaster(
            $companyId,
            (int) $authUser->id,
            (string) $payload['kind'],
            (string) $payload['name']
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function updateProductMaster(UpdateProductMasterRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->canManageProductMasters($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para gestionar maestros de producto.'], 403);
        }

        $payload = $request->validated();
        $result = $this->inventoryProductService->updateProductMaster(
            $companyId,
            $id,
            (string) $payload['kind'],
            $payload
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    private function canManageProducts($authUser, int $companyId): bool
    {
        return $this->isAllowedByProfileFeature($authUser, $companyId, self::FEATURE_PRODUCTS_BY_PROFILE);
    }

    private function canManageProductMasters($authUser, int $companyId): bool
    {
        return $this->isAllowedByProfileFeature($authUser, $companyId, self::FEATURE_PRODUCT_MASTERS_BY_PROFILE);
    }

    private function isAllowedByProfileFeature($authUser, int $companyId, string $featureCode): bool
    {
        return $this->companyFeatureAuthorizationService->isAllowedByProfileFeature(
            $authUser,
            $companyId,
            $featureCode
        );
    }
}
