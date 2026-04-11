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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    private const FEATURE_PRODUCTS_BY_PROFILE = 'INVENTORY_PRODUCTS_BY_PROFILE';
    private const FEATURE_PRODUCT_MASTERS_BY_PROFILE = 'INVENTORY_PRODUCT_MASTERS_BY_PROFILE';

    public function __construct(
        private GetProductLookupsUseCase $getProductLookupsUseCase,
        private CreateInventoryStockEntryUseCase $createInventoryStockEntryUseCase,
        private GetCurrentStockUseCase $getCurrentStockUseCase,
        private GetInventoryLotsUseCase $getInventoryLotsUseCase,
        private GetInventoryStockEntriesUseCase $getInventoryStockEntriesUseCase,
        private GetInventoryKardexUseCase $getInventoryKardexUseCase,
        private GetInventoryProductCommercialConfigUseCase $getInventoryProductCommercialConfigUseCase,
        private UpdateInventoryProductCommercialConfigUseCase $updateInventoryProductCommercialConfigUseCase
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
        $limit = (int) $request->query('limit', 100);

        $this->ensureProductCatalogSchema();

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $query = DB::table('inventory.products as p')
            ->leftJoin('inventory.categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('core.units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('inventory.product_lines as pl', 'pl.id', '=', 'p.line_id')
            ->leftJoin('inventory.product_brands as pb', 'pb.id', '=', 'p.brand_id')
            ->leftJoin('inventory.product_locations as plo', 'plo.id', '=', 'p.location_id')
            ->leftJoin('inventory.product_warranties as pw', 'pw.id', '=', 'p.warranty_id')
            ->select([
                'p.id',
                'p.sku',
                'p.barcode',
                'p.unit_id',
                'p.name',
                'p.sale_price',
                'p.cost_price',
                'p.line_id',
                'p.brand_id',
                'p.location_id',
                'p.warranty_id',
                'p.product_nature',
                'p.sunat_code',
                'p.image_url',
                'p.seller_commission_percent',
                'p.is_stockable',
                'p.lot_tracking',
                'p.has_expiration',
                'p.status',
                DB::raw('c.name as category_name'),
                DB::raw('pl.name as line_name'),
                DB::raw('pb.name as brand_name'),
                DB::raw('plo.name as location_name'),
                DB::raw('pw.name as warranty_name'),
                DB::raw('u.code as unit_code'),
                DB::raw('u.name as unit_name'),
            ])
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->orderBy('p.name')
            ->limit($limit);

        if ($search !== '') {
            $query->where(function ($nested) use ($search) {
                $nested->where('p.name', 'like', '%' . $search . '%')
                    ->orWhere('p.sku', 'like', '%' . $search . '%')
                    ->orWhere('p.barcode', 'like', '%' . $search . '%');
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('p.status', (int) $status);
        }

        return response()->json([
            'data' => $query->get(),
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

        $payload = $validator->validated();

        foreach ([
            ['line_id', 'inventory.product_lines'],
            ['brand_id', 'inventory.product_brands'],
            ['location_id', 'inventory.product_locations'],
            ['warranty_id', 'inventory.product_warranties'],
        ] as $masterRule) {
            [$field, $table] = $masterRule;
            if (!empty($payload[$field]) && !$this->productMasterExists($table, (int) $payload[$field], $companyId)) {
                return response()->json(['message' => 'Invalid ' . $field], 422);
            }
        }

        $id = DB::table('inventory.products')->insertGetId([
            'company_id' => $companyId,
            'category_id' => $payload['category_id'] ?? null,
            'unit_id' => $payload['unit_id'] ?? null,
            'line_id' => $payload['line_id'] ?? null,
            'brand_id' => $payload['brand_id'] ?? null,
            'location_id' => $payload['location_id'] ?? null,
            'warranty_id' => $payload['warranty_id'] ?? null,
            'product_nature' => $payload['product_nature'] ?? 'PRODUCT',
            'sku' => isset($payload['sku']) ? strtoupper(trim($payload['sku'])) : null,
            'barcode' => $payload['barcode'] ?? null,
            'sunat_code' => $payload['sunat_code'] ?? null,
            'image_url' => $payload['image_url'] ?? null,
            'seller_commission_percent' => $payload['seller_commission_percent'] ?? 0,
            'name' => trim($payload['name']),
            'sale_price' => $payload['sale_price'] ?? 0,
            'cost_price' => $payload['cost_price'] ?? 0,
            'is_stockable' => (bool) ($payload['is_stockable'] ?? true),
            'lot_tracking' => (bool) ($payload['lot_tracking'] ?? false),
            'has_expiration' => (bool) ($payload['has_expiration'] ?? false),
            'status' => (int) ($payload['status'] ?? 1),
        ]);

        return response()->json(['message' => 'Product created', 'id' => (int) $id], 201);
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

        $exists = DB::table('inventory.products')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $payload = $validator->validated();
        $changes = [];

        foreach ([
            ['line_id', 'inventory.product_lines'],
            ['brand_id', 'inventory.product_brands'],
            ['location_id', 'inventory.product_locations'],
            ['warranty_id', 'inventory.product_warranties'],
        ] as $masterRule) {
            [$field, $table] = $masterRule;
            if (array_key_exists($field, $payload) && !empty($payload[$field]) && !$this->productMasterExists($table, (int) $payload[$field], $companyId)) {
                return response()->json(['message' => 'Invalid ' . $field], 422);
            }
        }

        if (array_key_exists('category_id', $payload)) {
            $changes['category_id'] = $payload['category_id'];
        }
        if (array_key_exists('unit_id', $payload)) {
            $changes['unit_id'] = $payload['unit_id'];
        }
        if (array_key_exists('line_id', $payload)) {
            $changes['line_id'] = $payload['line_id'];
        }
        if (array_key_exists('brand_id', $payload)) {
            $changes['brand_id'] = $payload['brand_id'];
        }
        if (array_key_exists('location_id', $payload)) {
            $changes['location_id'] = $payload['location_id'];
        }
        if (array_key_exists('warranty_id', $payload)) {
            $changes['warranty_id'] = $payload['warranty_id'];
        }
        if (array_key_exists('product_nature', $payload)) {
            $changes['product_nature'] = $payload['product_nature'];
        }
        if (array_key_exists('sku', $payload)) {
            $changes['sku'] = $payload['sku'] ? strtoupper(trim($payload['sku'])) : null;
        }
        if (array_key_exists('barcode', $payload)) {
            $changes['barcode'] = $payload['barcode'];
        }
        if (array_key_exists('sunat_code', $payload)) {
            $changes['sunat_code'] = $payload['sunat_code'];
        }
        if (array_key_exists('image_url', $payload)) {
            $changes['image_url'] = $payload['image_url'];
        }
        if (array_key_exists('seller_commission_percent', $payload)) {
            $changes['seller_commission_percent'] = $payload['seller_commission_percent'];
        }
        if (array_key_exists('name', $payload)) {
            $changes['name'] = trim($payload['name']);
        }
        if (array_key_exists('sale_price', $payload)) {
            $changes['sale_price'] = $payload['sale_price'];
        }
        if (array_key_exists('cost_price', $payload)) {
            $changes['cost_price'] = $payload['cost_price'];
        }
        if (array_key_exists('is_stockable', $payload)) {
            $changes['is_stockable'] = (bool) $payload['is_stockable'];
        }
        if (array_key_exists('lot_tracking', $payload)) {
            $changes['lot_tracking'] = (bool) $payload['lot_tracking'];
        }
        if (array_key_exists('has_expiration', $payload)) {
            $changes['has_expiration'] = (bool) $payload['has_expiration'];
        }
        if (array_key_exists('status', $payload)) {
            $changes['status'] = (int) $payload['status'];
        }

        if (empty($changes)) {
            return response()->json(['message' => 'No changes provided'], 422);
        }

        DB::table('inventory.products')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($changes);

        return response()->json(['message' => 'Product updated']);
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
        $limit       = min((int) $request->query('limit', 100), 500);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        return response()->json([
            'data' => $this->getInventoryKardexUseCase->execute($companyId, $productId, $warehouseId, $dateFrom, $dateTo, $limit),
        ]);
    }

    public function createStockEntry(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'entry_type' => 'required|string|in:PURCHASE,ADJUSTMENT,PURCHASE_ORDER',
            'reference_no' => 'nullable|string|max:60',
            'supplier_reference' => 'nullable|string|max:120',
            'payment_method_id' => 'nullable|integer|min:1',
            'issue_at' => 'nullable|date',
            'notes' => 'nullable|string|max:300',
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
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_units (
                company_id BIGINT NOT NULL,
                unit_id BIGINT NOT NULL,
                is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, unit_id)
            )'
        );
    }

    private function ensureProductCatalogSchema(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.product_lines (id BIGSERIAL PRIMARY KEY, company_id BIGINT NOT NULL, name VARCHAR(120) NOT NULL, status SMALLINT NOT NULL DEFAULT 1, created_by BIGINT NULL, created_at TIMESTAMPTZ NULL, updated_at TIMESTAMPTZ NULL, UNIQUE(company_id, name))');
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.product_brands (id BIGSERIAL PRIMARY KEY, company_id BIGINT NOT NULL, name VARCHAR(120) NOT NULL, status SMALLINT NOT NULL DEFAULT 1, created_by BIGINT NULL, created_at TIMESTAMPTZ NULL, updated_at TIMESTAMPTZ NULL, UNIQUE(company_id, name))');
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.product_locations (id BIGSERIAL PRIMARY KEY, company_id BIGINT NOT NULL, name VARCHAR(120) NOT NULL, status SMALLINT NOT NULL DEFAULT 1, created_by BIGINT NULL, created_at TIMESTAMPTZ NULL, updated_at TIMESTAMPTZ NULL, UNIQUE(company_id, name))');
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.product_warranties (id BIGSERIAL PRIMARY KEY, company_id BIGINT NOT NULL, name VARCHAR(120) NOT NULL, status SMALLINT NOT NULL DEFAULT 1, created_by BIGINT NULL, created_at TIMESTAMPTZ NULL, updated_at TIMESTAMPTZ NULL, UNIQUE(company_id, name))');
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS line_id BIGINT NULL");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS brand_id BIGINT NULL");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS location_id BIGINT NULL");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS warranty_id BIGINT NULL");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS product_nature VARCHAR(20) NOT NULL DEFAULT 'PRODUCT'");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS sunat_code VARCHAR(40) NULL");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS image_url TEXT NULL");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS seller_commission_percent NUMERIC(8,4) NOT NULL DEFAULT 0");
    }

    private function productMasterExists(string $table, int $id, int $companyId): bool
    {
        return DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    public function productMasters(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        $this->ensureProductCatalogSchema();

        $lines = DB::table('inventory.product_lines')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')->get();

        $brands = DB::table('inventory.product_brands')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')->get();

        $locations = DB::table('inventory.product_locations')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')->get();

        $warranties = DB::table('inventory.product_warranties')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')->get();

        return response()->json([
            'lines' => $lines,
            'brands' => $brands,
            'locations' => $locations,
            'warranties' => $warranties,
        ]);
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

        $tableMap = [
            'line'      => 'inventory.product_lines',
            'brand'     => 'inventory.product_brands',
            'location'  => 'inventory.product_locations',
            'warranty'  => 'inventory.product_warranties',
        ];

        $table = $tableMap[$request->input('kind')];
        $name  = trim($request->input('name'));

        $existing = DB::table($table)
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->first();

        if ($existing) {
            if ((int) $existing->status !== 1) {
                DB::table($table)
                    ->where('id', (int) $existing->id)
                    ->update([
                        'status' => 1,
                        'updated_at' => now(),
                    ]);
            }

            return response()->json(['id' => (int) $existing->id, 'name' => $existing->name, 'status' => 1]);
        }

        $id = DB::table($table)->insertGetId([
            'company_id' => $companyId,
            'name'       => $name,
            'status'     => 1,
            'created_by' => $authUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => (int) $id, 'name' => $name, 'status' => 1], 201);
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

        $tableMap = [
            'line'      => 'inventory.product_lines',
            'brand'     => 'inventory.product_brands',
            'location'  => 'inventory.product_locations',
            'warranty'  => 'inventory.product_warranties',
        ];

        $payload = $validator->validated();
        $table = $tableMap[$payload['kind']];

        $master = DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$master) {
            return response()->json(['message' => 'Master not found'], 404);
        }

        $changes = [];

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return response()->json(['message' => 'Name cannot be empty'], 422);
            }

            $duplicate = DB::table($table)
                ->where('company_id', $companyId)
                ->where('id', '<>', $id)
                ->whereRaw('LOWER(name) = LOWER(?)', [$name])
                ->exists();

            if ($duplicate) {
                return response()->json(['message' => 'Name already exists'], 422);
            }

            $changes['name'] = $name;
        }

        if (array_key_exists('status', $payload)) {
            $changes['status'] = (int) $payload['status'];
        }

        if (empty($changes)) {
            return response()->json(['message' => 'No changes provided'], 422);
        }

        $changes['updated_at'] = now();

        DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($changes);

        $updated = DB::table($table)
            ->select('id', 'name', 'status')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return response()->json([
            'id' => (int) $updated->id,
            'name' => $updated->name,
            'status' => (int) $updated->status,
        ]);
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
        $row = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        if (!$row || !(bool) $row->is_enabled) {
            return true;
        }

        $config = [];
        if ($row->config !== null) {
            $decoded = json_decode((string) $row->config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        $allowSeller = (bool) ($config['allow_seller'] ?? true);
        $allowCashier = (bool) ($config['allow_cashier'] ?? true);
        $allowAdmin = (bool) ($config['allow_admin'] ?? true);

        $roleProfile = strtoupper((string) ($authUser->role_profile ?? ''));
        $roleCode = strtoupper((string) ($authUser->role_code ?? ''));

        if ($roleProfile === 'SELLER') {
            return $allowSeller;
        }
        if ($roleProfile === 'CASHIER') {
            return $allowCashier;
        }
        if ($roleCode === 'ADMIN' || $roleProfile === 'GENERAL') {
            return $allowAdmin;
        }

        return $allowAdmin;
    }
}
