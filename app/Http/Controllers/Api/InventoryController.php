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
use App\Services\Inventory\InventoryControllerSupportService;
use App\Services\Inventory\InventoryProductService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    private const FEATURE_PRODUCTS_BY_PROFILE = 'INVENTORY_PRODUCTS_BY_PROFILE';
    private const FEATURE_PRODUCT_MASTERS_BY_PROFILE = 'INVENTORY_PRODUCT_MASTERS_BY_PROFILE';

    /** In-memory projection used by applyCurrentStockDelta to detect negative stock within a request. */
    private array $stockProjection = [];

    public function __construct(
        private GetProductLookupsUseCase $getProductLookupsUseCase,
        private CreateInventoryStockEntryUseCase $createInventoryStockEntryUseCase,
        private GetCurrentStockUseCase $getCurrentStockUseCase,
        private GetInventoryLotsUseCase $getInventoryLotsUseCase,
        private GetInventoryStockEntriesUseCase $getInventoryStockEntriesUseCase,
        private GetInventoryKardexUseCase $getInventoryKardexUseCase,
        private GetInventoryProductCommercialConfigUseCase $getInventoryProductCommercialConfigUseCase,
        private UpdateInventoryProductCommercialConfigUseCase $updateInventoryProductCommercialConfigUseCase,
        private InventoryControllerSupportService $inventoryControllerSupportService,
        private InventoryProductService $inventoryProductService
    )
    {
    }

    public function productLookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        $this->ensureProductCatalogSchema();
        $this->ensureCompanyUnitsTable();

        $lookups = $this->getProductLookupsUseCase->execute($companyId);

        return response()->json([
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
        ]);
    }

    public function products(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $limit = (int) $request->query('limit', 2000);
        $autocomplete = filter_var($request->query('autocomplete', false), FILTER_VALIDATE_BOOLEAN);

        $this->ensureProductCatalogSchema();

        return response()->json([
            'data' => $this->inventoryProductService->listProducts($companyId, $search, $status, $limit, $autocomplete),
        ]);
    }

    public function createProduct(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        $this->ensureProductCatalogSchema();

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para guardar productos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|integer|min:1',
            'unit_id' => 'nullable|integer|min:1',
            'line_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'warranty_id' => 'nullable|integer|min:1',
            'product_nature' => 'nullable|string|in:PRODUCT,SUPPLY',
            'sku' => 'nullable|string|max:60',
            'barcode' => 'nullable|string|max:80',
            'sunat_code' => 'nullable|string|max:40',
            'image_url' => 'nullable|string|max:500',
            'seller_commission_percent' => 'nullable|numeric|min:0|max:100',
            'name' => 'required|string|max:180',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'is_stockable' => 'nullable|boolean',
            'lot_tracking' => 'nullable|boolean',
            'has_expiration' => 'nullable|boolean',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $result = $this->inventoryProductService->createProduct($companyId, $validator->validated());
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

    public function updateProduct(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        $this->ensureProductCatalogSchema();

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para guardar productos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|integer|min:1',
            'unit_id' => 'nullable|integer|min:1',
            'line_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'warranty_id' => 'nullable|integer|min:1',
            'product_nature' => 'nullable|string|in:PRODUCT,SUPPLY',
            'sku' => 'nullable|string|max:60',
            'barcode' => 'nullable|string|max:80',
            'sunat_code' => 'nullable|string|max:40',
            'image_url' => 'nullable|string|max:500',
            'seller_commission_percent' => 'nullable|numeric|min:0|max:100',
            'name' => 'nullable|string|max:180',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'is_stockable' => 'nullable|boolean',
            'lot_tracking' => 'nullable|boolean',
            'has_expiration' => 'nullable|boolean',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $result = $this->inventoryProductService->updateProduct($companyId, $id, $validator->validated());
        if (!$result['ok']) {
            $payload = ['message' => $result['message']];
            if (isset($result['duplicate_product_id'])) {
                $payload['duplicate_product_id'] = $result['duplicate_product_id'];
            }

            return response()->json($payload, (int) $result['status']);
        }

        return response()->json(['message' => $result['message']], (int) $result['status']);
    }

    public function bulkImportProducts(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        $this->ensureProductCatalogSchema();

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para importar productos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'rows'                    => 'required|array|min:1|max:5000',
            'rows.*.id'               => 'nullable|integer|min:1',
            'rows.*.sku'              => 'nullable|string|max:60',
            'rows.*.barcode'          => 'nullable|string|max:80',
            'rows.*.name'             => 'nullable|string|max:180',
            'rows.*.product_nature'   => 'nullable|string|max:30',
            'rows.*.sale_price'       => 'nullable',
            'rows.*.cost_price'       => 'nullable',
            'rows.*.unit_code'        => 'nullable|string|max:40',
            'rows.*.sunat_code'       => 'nullable|string|max:40',
            'rows.*.is_stockable'     => 'nullable',
            'rows.*.lot_tracking'     => 'nullable',
            'rows.*.has_expiration'   => 'nullable',
            'rows.*.status'           => 'nullable',
            'rows.*.initial_qty'      => 'nullable',
            'rows.*.initial_cost'     => 'nullable',
            'rows.*.warehouse_code'   => 'nullable|string|max:50',
            'warehouse_code'          => 'nullable|string|max:50',
            'filename'                => 'nullable|string|max:300',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $result = $this->inventoryProductService->bulkImportProducts($companyId, (int) $authUser->id, $validator->validated());
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function bulkUpdateProductStock(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        $this->ensureProductCatalogSchema();

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para actualizar stock.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.id' => 'nullable|integer|min:1',
            'rows.*.sku' => 'nullable|string|max:60',
            'rows.*.warehouse_code' => 'nullable|string|max:50',
            'rows.*.qty' => 'required|numeric',
            'rows.*.note' => 'nullable|string|max:300',
            'rows.*.metadata' => 'nullable|array',
            'mode' => 'required|string|in:add,replace',
            'warehouse_code' => 'nullable|string|max:50',
            'filename' => 'nullable|string|max:300',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $result = $this->inventoryProductService->bulkUpdateProductStock($companyId, (int) $authUser->id, $validator->validated());
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function productImportBatches(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $limit = (int) $request->query('limit', 30);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $this->ensureProductCatalogSchema();

        $result = $this->inventoryProductService->listImportBatches($companyId, $limit);

        return response()->json([
            'data' => $result['data'],
        ], (int) $result['status']);
    }

    public function productImportBatchDetail(Request $request, int $batchId)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $itemsLimit = (int) $request->query('items_limit', 500);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $this->ensureProductCatalogSchema();

        $result = $this->inventoryProductService->getImportBatchDetail($companyId, $batchId, $itemsLimit);
        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function currentStock(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $warehouseId = $request->query('warehouse_id');
        $productId = $request->query('product_id');

        return response()->json([
            'data' => $this->getCurrentStockUseCase->execute($companyId, $warehouseId, $productId),
        ]);
    }

    public function lots(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $warehouseId = $request->query('warehouse_id');
        $productId = $request->query('product_id');
        $onlyWithStock = filter_var($request->query('only_with_stock', true), FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'data' => $this->getInventoryLotsUseCase->execute($companyId, $warehouseId, $productId, $onlyWithStock),
        ]);
    }

    public function stockEntries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
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
        $authUser    = $request->attributes->get('auth_user');
        $companyId   = (int) $request->query('company_id', $authUser->company_id);
        $productId   = $request->query('product_id');
        $warehouseId = $request->query('warehouse_id');
        $dateFrom    = $request->query('date_from');
        $dateTo      = $request->query('date_to');
        $perPage     = min(max((int) $request->query('per_page', 50), 1), 200);
        $page        = max((int) $request->query('page', 1), 1);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $result = $this->getInventoryKardexUseCase->execute($companyId, $productId, $warehouseId, $dateFrom, $dateTo, $perPage, $page);

        return response()->json($result);
    }

    public function createStockEntry(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'entry_type' => 'required|string|in:PURCHASE,ADJUSTMENT,PURCHASE_ORDER',
            'reference_no' => 'required_if:entry_type,PURCHASE,PURCHASE_ORDER|string|max:60',
            'supplier_reference' => 'required_if:entry_type,PURCHASE,PURCHASE_ORDER|string|max:120',
            'payment_method_id' => 'nullable|integer|min:1',
            'issue_at' => 'nullable|date',
            'notes' => 'required_if:entry_type,ADJUSTMENT|nullable|string|max:300',
            'metadata' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty' => 'required|numeric',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.lot_id' => 'nullable|integer|min:1',
            'items.*.lot_code' => 'nullable|string|max:80',
            'items.*.manufacture_at' => 'nullable|date',
            'items.*.expires_at' => 'nullable|date',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.notes' => 'nullable|string|max:200',
            'items.*.metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);
        $branchId = array_key_exists('branch_id', $payload) ? $payload['branch_id'] : $authUser->branch_id;
        $warehouseId = (int) $payload['warehouse_id'];

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

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
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $config = $this->getInventoryProductCommercialConfigUseCase->execute($companyId, $id);
        if ($config === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($config);
    }

    public function updateProductCommercialConfig(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->canManageProducts($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para guardar productos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'base_unit_id' => 'nullable|integer|min:1',
            'units' => 'nullable|array',
            'units.*.unit_id' => 'required_with:units|integer|min:1',
            'units.*.is_base' => 'nullable|boolean',
            'units.*.status' => 'nullable|integer|in:0,1',
            'conversions' => 'nullable|array',
            'conversions.*.from_unit_id' => 'required_with:conversions|integer|min:1',
            'conversions.*.to_unit_id' => 'required_with:conversions|integer|min:1',
            'conversions.*.conversion_factor' => 'required_with:conversions|numeric|min:0.00000001',
            'conversions.*.status' => 'nullable|integer|in:0,1',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.price_tier_id' => 'required_with:wholesale_prices|integer|min:1',
            'wholesale_prices.*.unit_id' => 'nullable|integer|min:1',
            'wholesale_prices.*.unit_price' => 'required_with:wholesale_prices|numeric|min:0',
            'wholesale_prices.*.status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
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

        $config = $this->getInventoryProductCommercialConfigUseCase->execute($companyId, $id);
        if ($config === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($config);
    }

    private function ensureCompanyUnitsTable(): void
    {
        $this->inventoryControllerSupportService->ensureCompanyUnitsTable();
    }

    private function ensureProductCatalogSchema(): void
    {
        $this->inventoryControllerSupportService->ensureProductCatalogSchema();
    }

    private function productMasterExists(string $table, int $id, int $companyId): bool
    {
        return $this->inventoryControllerSupportService->productMasterExists($table, $id, $companyId);
    }

    public function productMasters(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        $this->ensureProductCatalogSchema();

        return response()->json($this->inventoryProductService->listProductMasters($companyId));
    }

    public function createProductMaster(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->canManageProductMasters($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para gestionar maestros de producto.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'kind' => 'required|string|in:line,brand,location,warranty',
            'name' => 'required|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $this->ensureProductCatalogSchema();
        $result = $this->inventoryProductService->createProductMaster(
            $companyId,
            (int) $authUser->id,
            (string) $request->input('kind'),
            (string) $request->input('name')
        );

        if (!$result['ok']) {
            return response()->json(['message' => $result['message']], (int) $result['status']);
        }

        return response()->json($result['data'], (int) $result['status']);
    }

    public function updateProductMaster(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$this->canManageProductMasters($authUser, $companyId)) {
            return response()->json(['message' => 'No tienes permiso para gestionar maestros de producto.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'kind' => 'required|string|in:line,brand,location,warranty',
            'name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $this->ensureProductCatalogSchema();
        $payload = $validator->validated();
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

    private function restaurantRecipesTableExists(): bool
    {
        return $this->inventoryControllerSupportService->restaurantRecipesTableExists();
    }

    private function restaurantRecipesDeletedAtColumnExists(): bool
    {
        return $this->inventoryControllerSupportService->restaurantRecipesDeletedAtColumnExists();
    }

    private function resolveDefaultUnitId(): ?int
    {
        return $this->inventoryControllerSupportService->resolveDefaultUnitId();
    }

    private function buildUnitLookupMap(): array
    {
        return $this->inventoryControllerSupportService->buildUnitLookupMap();
    }

    private function resolveUnitIdFromCode(string $unitCode, array $unitMap, int $defaultUnitId): int
    {
        $normalized = strtoupper(trim($unitCode));
        if ($normalized === '') {
            return $defaultUnitId;
        }

        return $unitMap[$normalized] ?? $defaultUnitId;
    }

    private function resolveWarehouseIdFromCode(int $companyId, string $warehouseCode, array &$cache): ?int
    {
        return $this->inventoryControllerSupportService->resolveWarehouseIdFromCode(
            $companyId,
            $warehouseCode,
            $cache
        );
    }

    private function resolveDefaultWarehouseForImport(int $companyId): ?array
    {
        return $this->inventoryControllerSupportService->resolveDefaultWarehouseForImport($companyId);
    }

    private function normalizeProductNature(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if (in_array($normalized, ['SUPPLY', 'INSUMO', 'INSUMOS'], true)) {
            return 'SUPPLY';
        }

        return 'PRODUCT';
    }

    private function normalizeNumeric($value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized)) {
            return $default;
        }

        return (float) $normalized;
    }

    private function applyCurrentStockDelta(int $companyId, int $warehouseId, int $productId, float $delta, bool $allowNegativeStock): void
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;

        if (!array_key_exists($projectionKey, $this->stockProjection)) {
            $this->stockProjection[$projectionKey] = $this->inventoryControllerSupportService->getCurrentStockForProjection(
                $companyId,
                $warehouseId,
                $productId
            );
        }

        $current = $this->stockProjection[$projectionKey];
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for product #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }

    private function normalizeBoolean($value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtoupper(trim((string) $value));
        if (in_array($normalized, ['1', 'TRUE', 'SI', 'S', 'YES', 'Y', 'ACTIVO'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'FALSE', 'NO', 'N', 'INACTIVO'], true)) {
            return false;
        }

        return $default;
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function findExistingActiveProduct(
        int $companyId,
        ?string $sku,
        ?string $barcode,
        string $name,
        ?int $unitId,
        string $nature,
        ?int $excludeProductId = null
    ): ?object {
        return $this->inventoryControllerSupportService->findExistingActiveProduct(
            $companyId,
            $sku,
            $barcode,
            $name,
            $unitId,
            $nature,
            $excludeProductId
        );
    }

    private function canManageProductMasters($authUser, int $companyId): bool
    {
        return $this->isAllowedByProfileFeature($authUser, $companyId, self::FEATURE_PRODUCT_MASTERS_BY_PROFILE);
    }

    private function isAllowedByProfileFeature($authUser, int $companyId, string $featureCode): bool
    {
        return $this->inventoryControllerSupportService->isAllowedByProfileFeature(
            $authUser,
            $companyId,
            $featureCode
        );
    }
}
