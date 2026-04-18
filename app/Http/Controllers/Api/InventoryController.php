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
                $nested->where('p.name', 'ilike', '%' . $search . '%')
                    ->orWhere('p.sku', 'ilike', '%' . $search . '%')
                    ->orWhere('p.barcode', 'ilike', '%' . $search . '%');
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

        $validated = $validator->validated();
        $rows = $validated['rows'];
        $filename = isset($validated['filename']) ? trim((string) $validated['filename']) : null;
        $defaultWarehouseCode = strtoupper(trim((string) ($validated['warehouse_code'] ?? '')));
        $userId = (int) $authUser->id;

        $defaultUnitId = $this->resolveDefaultUnitId();
        if ($defaultUnitId === null) {
            return response()->json([
                'message' => 'No se encontró la unidad por defecto NIU (UNIDAD (BIENES)) en core.units.',
            ], 422);
        }

        // Registrar el lote de importación ANTES de procesar para trazabilidad completa.
        $batchId = (int) DB::table('inventory.product_import_batches')->insertGetId([
            'company_id'   => $companyId,
            'imported_by'  => $userId,
            'filename'     => ($filename !== '' ? $filename : null),
            'total_rows'   => count($rows),
            'status'       => 'PROCESSING',
            'started_at'   => now(),
            'created_at'   => now(),
        ]);

        $unitMap = $this->buildUnitLookupMap();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $itemLogs = [];
        $seenRowKeys = [];
        $warehouseCache = [];
        $stockApplied = 0;
        $stockSkipped = 0;
        $defaultWarehouse = null;

        if ($defaultWarehouseCode !== '') {
            $resolvedTopLevelWarehouseId = $this->resolveWarehouseIdFromCode($companyId, $defaultWarehouseCode, $warehouseCache);
            if ($resolvedTopLevelWarehouseId === null) {
                return response()->json([
                    'message' => 'warehouse_code por defecto no existe o está inactivo.',
                ], 422);
            }

            $defaultWarehouse = [
                'id' => $resolvedTopLevelWarehouseId,
                'code' => $defaultWarehouseCode,
            ];
        } else {
            $defaultWarehouse = $this->resolveDefaultWarehouseForImport($companyId);
        }

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Nombre es obligatorio.'];
                $itemLogs[] = [
                    'batch_id'      => $batchId,
                    'row_number'    => $rowNumber,
                    'action_status' => 'SKIPPED',
                    'product_id'    => null,
                    'sku'           => null,
                    'barcode'       => null,
                    'name'          => null,
                    'message'       => 'Nombre es obligatorio.',
                    'created_at'    => now(),
                ];
                continue;
            }

            $skuRaw     = trim((string) ($row['sku'] ?? ''));
            $barcodeRaw = trim((string) ($row['barcode'] ?? ''));
            $unitCodeRaw = trim((string) ($row['unit_code'] ?? ''));

            $sku     = $skuRaw !== '' ? strtoupper($skuRaw) : null;
            $barcode = $barcodeRaw !== '' ? $barcodeRaw : null;
            $unitId  = $this->resolveUnitIdFromCode($unitCodeRaw, $unitMap, $defaultUnitId);
            $nature  = $this->normalizeProductNature((string) ($row['product_nature'] ?? ''));

            $payload = [
                'unit_id'       => $unitId,
                'product_nature' => $nature,
                'sku'           => $sku,
                'barcode'       => $barcode,
                'sunat_code'    => $this->nullIfBlank((string) ($row['sunat_code'] ?? '')),
                'name'          => $name,
                'sale_price'    => $this->normalizeNumeric($row['sale_price'] ?? null, 0),
                'cost_price'    => $this->normalizeNumeric($row['cost_price'] ?? null, 0),
                'is_stockable'  => $this->normalizeBoolean($row['is_stockable'] ?? null, true),
                'lot_tracking'  => $this->normalizeBoolean($row['lot_tracking'] ?? null, false),
                'has_expiration' => $this->normalizeBoolean($row['has_expiration'] ?? null, false),
                'status'        => $this->normalizeBoolean($row['status'] ?? null, true) ? 1 : 0,
            ];

            // Evita que una misma fila funcional se procese varias veces dentro del mismo archivo.
            $rowUniqueKey = null;
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $rowUniqueKey = 'ID:' . $id;
            } elseif ($sku !== null) {
                $rowUniqueKey = 'SKU:' . $sku;
            } elseif ($barcode !== null) {
                $rowUniqueKey = 'BARCODE:' . $barcode;
            } else {
                $rowUniqueKey = 'NAME:' . strtoupper(trim($name)) . '|UNIT:' . (string) $unitId . '|NATURE:' . $nature;
            }

            if (isset($seenRowKeys[$rowUniqueKey])) {
                $skipped++;
                $duplicateMessage = 'Fila duplicada dentro del archivo (misma clave de importación).';
                $errors[] = ['row' => $rowNumber, 'message' => $duplicateMessage];
                $itemLogs[] = [
                    'batch_id'      => $batchId,
                    'row_number'    => $rowNumber,
                    'action_status' => 'SKIPPED',
                    'product_id'    => null,
                    'sku'           => $sku,
                    'barcode'       => $barcode,
                    'name'          => $name,
                    'message'       => $duplicateMessage,
                    'created_at'    => now(),
                ];
                continue;
            }

            $seenRowKeys[$rowUniqueKey] = true;

            $existing = null;

            if ($id > 0) {
                $existing = DB::table('inventory.products')
                    ->where('id', $id)
                    ->where('company_id', $companyId)
                    ->select('id')
                    ->first();
            }

            if (!$existing && $sku !== null) {
                $existing = DB::table('inventory.products')
                    ->where('company_id', $companyId)
                    ->whereRaw("UPPER(COALESCE(sku, '')) = ?", [$sku])
                    ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                    ->select('id')
                    ->first();
            }

            if (!$existing && $barcode !== null) {
                $existing = DB::table('inventory.products')
                    ->where('company_id', $companyId)
                    ->where('barcode', $barcode)
                    ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                    ->select('id')
                    ->first();
            }

            if (!$existing && $sku === null && $barcode === null) {
                $existing = DB::table('inventory.products')
                    ->where('company_id', $companyId)
                    ->whereRaw("UPPER(TRIM(COALESCE(name, ''))) = ?", [strtoupper(trim($name))])
                    ->where('unit_id', $unitId)
                    ->where('product_nature', $nature)
                    ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                    ->select('id')
                    ->first();
            }

            if ($existing) {
                DB::table('inventory.products')
                    ->where('id', (int) $existing->id)
                    ->where('company_id', $companyId)
                    ->update(array_merge($payload, [
                        'deleted_at' => null,
                        'updated_at' => now(),
                        'updated_by' => $userId,
                    ]));

                $stockMessage = null;
                $initialQtyRaw = trim((string) ($row['initial_qty'] ?? ''));
                $initialQty = $this->normalizeNumeric($row['initial_qty'] ?? null, 0);
                if ($initialQtyRaw !== '') {
                    if ($initialQty > 0 && (bool) $payload['is_stockable']) {
                        $warehouseId = null;
                        $warehouseCodeUsed = null;
                        $usedDefaultByInvalidRowWarehouse = false;
                        $rowWarehouseCode = strtoupper(trim((string) ($row['warehouse_code'] ?? '')));
                        if ($rowWarehouseCode !== '') {
                            $warehouseId = $this->resolveWarehouseIdFromCode($companyId, $rowWarehouseCode, $warehouseCache);
                            if ($warehouseId !== null) {
                                $warehouseCodeUsed = $rowWarehouseCode;
                            } elseif ($defaultWarehouse !== null) {
                                $warehouseId = (int) $defaultWarehouse['id'];
                                $warehouseCodeUsed = (string) $defaultWarehouse['code'];
                                $usedDefaultByInvalidRowWarehouse = true;
                            }
                        } elseif ($defaultWarehouse !== null) {
                            $warehouseId = (int) $defaultWarehouse['id'];
                            $warehouseCodeUsed = (string) $defaultWarehouse['code'];
                        }

                        if ($warehouseId === null) {
                            $stockSkipped++;
                            $stockMessage = $rowWarehouseCode !== ''
                                ? 'Stock inicial no aplicado: warehouse_code no existe o inactivo.'
                                : 'Stock inicial no aplicado: no existe almacén activo para usar como principal.';
                            $errors[] = ['row' => $rowNumber, 'message' => $stockMessage];
                        } else {
                            DB::table('inventory.inventory_ledger')->insert([
                                'company_id' => $companyId,
                                'warehouse_id' => $warehouseId,
                                'product_id' => (int) $existing->id,
                                'lot_id' => null,
                                'movement_type' => 'IN',
                                'quantity' => round($initialQty, 3),
                                'unit_cost' => round($this->normalizeNumeric($row['initial_cost'] ?? $payload['cost_price'] ?? 0, 0), 4),
                                'ref_type' => 'PRODUCT_IMPORT',
                                'ref_id' => $batchId,
                                'notes' => 'Importacion masiva lote #' . $batchId,
                                'moved_at' => now(),
                                'created_by' => $userId,
                            ]);
                            $stockApplied++;
                            $stockMessage = $usedDefaultByInvalidRowWarehouse
                                ? ('Stock inicial aplicado en almacén ' . $warehouseCodeUsed . ' (warehouse_code inválido en fila).')
                                : ('Stock inicial aplicado en almacén ' . $warehouseCodeUsed . '.');
                        }
                    } elseif ($initialQty <= 0) {
                        $stockSkipped++;
                        $stockMessage = 'Stock inicial no aplicado: initial_qty debe ser mayor a 0.';
                        $errors[] = ['row' => $rowNumber, 'message' => $stockMessage];
                    }
                }

                $itemLogs[] = [
                    'batch_id'      => $batchId,
                    'row_number'    => $rowNumber,
                    'action_status' => 'UPDATED',
                    'product_id'    => (int) $existing->id,
                    'sku'           => $sku,
                    'barcode'       => $barcode,
                    'name'          => $name,
                    'message'       => $stockMessage ? ('Producto actualizado. ' . $stockMessage) : 'Producto actualizado.',
                    'created_at'    => now(),
                ];

                $updated++;
                continue;
            }

            $newProductId = (int) DB::table('inventory.products')->insertGetId(array_merge($payload, [
                'company_id' => $companyId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]));

            $stockMessage = null;
            $initialQtyRaw = trim((string) ($row['initial_qty'] ?? ''));
            $initialQty = $this->normalizeNumeric($row['initial_qty'] ?? null, 0);
            if ($initialQtyRaw !== '') {
                if ($initialQty > 0 && (bool) $payload['is_stockable']) {
                    $warehouseId = null;
                    $warehouseCodeUsed = null;
                    $usedDefaultByInvalidRowWarehouse = false;
                    $rowWarehouseCode = strtoupper(trim((string) ($row['warehouse_code'] ?? '')));
                    if ($rowWarehouseCode !== '') {
                        $warehouseId = $this->resolveWarehouseIdFromCode($companyId, $rowWarehouseCode, $warehouseCache);
                        if ($warehouseId !== null) {
                            $warehouseCodeUsed = $rowWarehouseCode;
                        } elseif ($defaultWarehouse !== null) {
                            $warehouseId = (int) $defaultWarehouse['id'];
                            $warehouseCodeUsed = (string) $defaultWarehouse['code'];
                            $usedDefaultByInvalidRowWarehouse = true;
                        }
                    } elseif ($defaultWarehouse !== null) {
                        $warehouseId = (int) $defaultWarehouse['id'];
                        $warehouseCodeUsed = (string) $defaultWarehouse['code'];
                    }

                    if ($warehouseId === null) {
                        $stockSkipped++;
                        $stockMessage = $rowWarehouseCode !== ''
                            ? 'Stock inicial no aplicado: warehouse_code no existe o inactivo.'
                            : 'Stock inicial no aplicado: no existe almacén activo para usar como principal.';
                        $errors[] = ['row' => $rowNumber, 'message' => $stockMessage];
                    } else {
                        DB::table('inventory.inventory_ledger')->insert([
                            'company_id' => $companyId,
                            'warehouse_id' => $warehouseId,
                            'product_id' => $newProductId,
                            'lot_id' => null,
                            'movement_type' => 'IN',
                            'quantity' => round($initialQty, 3),
                            'unit_cost' => round($this->normalizeNumeric($row['initial_cost'] ?? $payload['cost_price'] ?? 0, 0), 4),
                            'ref_type' => 'PRODUCT_IMPORT',
                            'ref_id' => $batchId,
                            'notes' => 'Importacion masiva lote #' . $batchId,
                            'moved_at' => now(),
                            'created_by' => $userId,
                        ]);
                        $stockApplied++;
                        $stockMessage = $usedDefaultByInvalidRowWarehouse
                            ? ('Stock inicial aplicado en almacén ' . $warehouseCodeUsed . ' (warehouse_code inválido en fila).')
                            : ('Stock inicial aplicado en almacén ' . $warehouseCodeUsed . '.');
                    }
                } elseif ($initialQty <= 0) {
                    $stockSkipped++;
                    $stockMessage = 'Stock inicial no aplicado: initial_qty debe ser mayor a 0.';
                    $errors[] = ['row' => $rowNumber, 'message' => $stockMessage];
                }
            }

            $itemLogs[] = [
                'batch_id'      => $batchId,
                'row_number'    => $rowNumber,
                'action_status' => 'CREATED',
                'product_id'    => $newProductId,
                'sku'           => $sku,
                'barcode'       => $barcode,
                'name'          => $name,
                'message'       => $stockMessage ? ('Producto creado. ' . $stockMessage) : 'Producto creado.',
                'created_at'    => now(),
            ];

            $created++;
        }

        if (!empty($itemLogs)) {
            foreach (array_chunk($itemLogs, 500) as $chunk) {
                DB::table('inventory.product_import_batch_items')->insert($chunk);
            }
        }

        // Cerrar el lote con el resultado final.
        $finalStatus = ($skipped > 0 || count($errors) > 0) ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED';
        DB::table('inventory.product_import_batches')
            ->where('id', $batchId)
            ->update([
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count'   => count($errors),
                'errors_json'   => count($errors) > 0 ? json_encode(array_slice($errors, 0, 300)) : null,
                'status'        => $finalStatus,
                'finished_at'   => now(),
            ]);

        return response()->json([
            'message'  => 'Importación masiva procesada.',
            'batch_id' => $batchId,
            'summary'  => [
                'total'   => count($rows),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors'  => count($errors),
                'stock_applied' => $stockApplied,
                'stock_skipped' => $stockSkipped,
            ],
            'errors' => array_slice($errors, 0, 300),
        ]);
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

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $rows = DB::table('inventory.product_import_batches as b')
            ->leftJoin('auth.users as u', 'u.id', '=', 'b.imported_by')
            ->where('b.company_id', $companyId)
            ->orderByDesc('b.id')
            ->limit($limit)
            ->select([
                'b.id',
                'b.company_id',
                'b.imported_by',
                'b.filename',
                'b.total_rows',
                'b.created_count',
                'b.updated_count',
                'b.skipped_count',
                'b.error_count',
                'b.status',
                'b.started_at',
                'b.finished_at',
                'b.created_at',
                DB::raw("TRIM(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')) as imported_by_name"),
                'u.username as imported_by_username',
            ])
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
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

        if ($itemsLimit < 1) {
            $itemsLimit = 1;
        }
        if ($itemsLimit > 2000) {
            $itemsLimit = 2000;
        }

        $batch = DB::table('inventory.product_import_batches as b')
            ->leftJoin('auth.users as u', 'u.id', '=', 'b.imported_by')
            ->where('b.id', $batchId)
            ->where('b.company_id', $companyId)
            ->select([
                'b.id',
                'b.company_id',
                'b.imported_by',
                'b.filename',
                'b.total_rows',
                'b.created_count',
                'b.updated_count',
                'b.skipped_count',
                'b.error_count',
                'b.errors_json',
                'b.status',
                'b.started_at',
                'b.finished_at',
                'b.created_at',
                DB::raw("TRIM(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')) as imported_by_name"),
                'u.username as imported_by_username',
            ])
            ->first();

        if (!$batch) {
            return response()->json(['message' => 'Lote de importación no encontrado'], 404);
        }

        $items = DB::table('inventory.product_import_batch_items')
            ->where('batch_id', $batchId)
            ->orderBy('id')
            ->limit($itemsLimit)
            ->get([
                'id',
                'batch_id',
                'row_number',
                'action_status',
                'product_id',
                'sku',
                'barcode',
                'name',
                'message',
                'created_at',
            ]);

        $errors = [];
        if ($batch->errors_json !== null) {
            $decoded = is_string($batch->errors_json)
                ? json_decode($batch->errors_json, true)
                : $batch->errors_json;
            if (is_array($decoded)) {
                $errors = $decoded;
            }
        }

        unset($batch->errors_json);

        return response()->json([
            'batch' => $batch,
            'items' => $items,
            'errors' => $errors,
        ]);
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
        // Columnas de auditoría en la tabla base de productos
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS created_by BIGINT NULL");
        DB::statement("ALTER TABLE inventory.products ADD COLUMN IF NOT EXISTS updated_by BIGINT NULL");
        // Tabla de trazabilidad de importaciones masivas
        DB::statement("
            CREATE TABLE IF NOT EXISTS inventory.product_import_batches (
                id           BIGSERIAL PRIMARY KEY,
                company_id   BIGINT NOT NULL,
                imported_by  BIGINT NOT NULL,
                filename     VARCHAR(300) NULL,
                total_rows   INT NOT NULL DEFAULT 0,
                created_count INT NOT NULL DEFAULT 0,
                updated_count INT NOT NULL DEFAULT 0,
                skipped_count INT NOT NULL DEFAULT 0,
                error_count  INT NOT NULL DEFAULT 0,
                errors_json  JSONB NULL,
                status       VARCHAR(40) NOT NULL DEFAULT 'PROCESSING',
                started_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                finished_at  TIMESTAMPTZ NULL,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_product_import_batches_company ON inventory.product_import_batches (company_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_product_import_batches_user ON inventory.product_import_batches (imported_by)');
        DB::statement("ALTER TABLE inventory.product_import_batches ADD COLUMN IF NOT EXISTS started_at TIMESTAMPTZ NOT NULL DEFAULT NOW()");
        DB::statement("ALTER TABLE inventory.product_import_batches ADD COLUMN IF NOT EXISTS finished_at TIMESTAMPTZ NULL");
        DB::statement("ALTER TABLE inventory.product_import_batches ADD COLUMN IF NOT EXISTS errors_json JSONB NULL");
        DB::statement("ALTER TABLE inventory.product_import_batches ALTER COLUMN status TYPE VARCHAR(40)");

        DB::statement("CREATE TABLE IF NOT EXISTS inventory.product_import_batch_items (
            id            BIGSERIAL PRIMARY KEY,
            batch_id      BIGINT NOT NULL REFERENCES inventory.product_import_batches(id) ON DELETE CASCADE,
            row_number    INT NOT NULL,
            action_status VARCHAR(20) NOT NULL,
            product_id    BIGINT NULL,
            sku           VARCHAR(60) NULL,
            barcode       VARCHAR(80) NULL,
            name          VARCHAR(250) NULL,
            message       VARCHAR(500) NULL,
            created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_product_import_batch_items_batch ON inventory.product_import_batch_items (batch_id, id)');
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

    private function resolveDefaultUnitId(): ?int
    {
        $row = DB::table('core.units')
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereRaw("UPPER(COALESCE(code, '')) = ?", ['NIU'])
                    ->orWhereRaw("UPPER(COALESCE(sunat_uom_code, '')) = ?", ['NIU'])
                    ->orWhereRaw("UPPER(COALESCE(name, '')) = ?", ['UNIDAD (BIENES)']);
            })
            ->select('id')
            ->orderBy('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    private function buildUnitLookupMap(): array
    {
        $rows = DB::table('core.units')
            ->where('status', 1)
            ->select('id', 'code', 'sunat_uom_code', 'name')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            foreach ([(string) $row->code, (string) $row->sunat_uom_code, (string) $row->name] as $key) {
                $normalized = strtoupper(trim($key));
                if ($normalized !== '') {
                    $map[$normalized] = $id;
                }
            }
        }

        return $map;
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
        $normalized = strtoupper(trim($warehouseCode));
        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, $cache)) {
            return $cache[$normalized];
        }

        $row = DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw("UPPER(COALESCE(code, '')) = ?", [$normalized])
                    ->orWhereRaw("UPPER(COALESCE(name, '')) = ?", [$normalized]);
            })
            ->where('status', 1)
            ->select('id')
            ->first();

        $cache[$normalized] = $row ? (int) $row->id : null;
        return $cache[$normalized];
    }

    private function resolveDefaultWarehouseForImport(int $companyId): ?array
    {
        $row = DB::table('inventory.warehouses as w')
            ->leftJoin('core.branches as b', function ($join) {
                $join->on('b.id', '=', 'w.branch_id')
                    ->on('b.company_id', '=', 'w.company_id');
            })
            ->where('w.company_id', $companyId)
            ->where('w.status', 1)
            ->orderByRaw("CASE
                WHEN UPPER(COALESCE(w.code, '')) IN ('WH01', 'PRINCIPAL', 'MAIN') THEN 0
                WHEN UPPER(COALESCE(w.name, '')) LIKE '%PRINCIPAL%' THEN 1
                WHEN COALESCE(b.is_main, false) = true THEN 2
                ELSE 3
            END")
            ->orderBy('w.id')
            ->select(['w.id', 'w.code'])
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => strtoupper(trim((string) ($row->code ?? ''))),
        ];
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
