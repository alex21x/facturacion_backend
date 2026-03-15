<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    private const FEATURE_MULTI_UOM = 'PRODUCT_MULTI_UOM';
    private const FEATURE_UOM_CONVERSIONS = 'PRODUCT_UOM_CONVERSIONS';
    private const FEATURE_WHOLESALE_PRICING = 'PRODUCT_WHOLESALE_PRICING';
    private $stockProjection = [];
    private $lotStockProjection = [];

    public function productLookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        $this->ensureCompanyUnitsTable();

        $units = DB::table('core.units as u')
            ->join('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select('u.id', 'u.code', 'u.name', 'u.sunat_uom_code')
            ->where('cu.is_enabled', true)
            ->orderBy('name')
            ->get();

        $categories = DB::table('inventory.categories')
            ->select('id', 'name')
            ->where('status', 1)
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'units' => $units,
            'categories' => $categories,
        ]);
    }

    public function products(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $limit = (int) $request->query('limit', 100);

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $query = DB::table('inventory.products as p')
            ->leftJoin('inventory.categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('core.units as u', 'u.id', '=', 'p.unit_id')
            ->select([
                'p.id',
                'p.sku',
                'p.barcode',
                'p.unit_id',
                'p.name',
                'p.sale_price',
                'p.cost_price',
                'p.is_stockable',
                'p.lot_tracking',
                'p.has_expiration',
                'p.status',
                DB::raw('c.name as category_name'),
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

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|integer|min:1',
            'unit_id' => 'nullable|integer|min:1',
            'sku' => 'nullable|string|max:60',
            'barcode' => 'nullable|string|max:80',
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

        $id = DB::table('inventory.products')->insertGetId([
            'company_id' => $companyId,
            'category_id' => $payload['category_id'] ?? null,
            'unit_id' => $payload['unit_id'] ?? null,
            'sku' => isset($payload['sku']) ? strtoupper(trim($payload['sku'])) : null,
            'barcode' => $payload['barcode'] ?? null,
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

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|integer|min:1',
            'unit_id' => 'nullable|integer|min:1',
            'sku' => 'nullable|string|max:60',
            'barcode' => 'nullable|string|max:80',
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

        if (array_key_exists('category_id', $payload)) {
            $changes['category_id'] = $payload['category_id'];
        }
        if (array_key_exists('unit_id', $payload)) {
            $changes['unit_id'] = $payload['unit_id'];
        }
        if (array_key_exists('sku', $payload)) {
            $changes['sku'] = $payload['sku'] ? strtoupper(trim($payload['sku'])) : null;
        }
        if (array_key_exists('barcode', $payload)) {
            $changes['barcode'] = $payload['barcode'];
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

        $query = DB::table('inventory.current_stock as cs')
            ->join('inventory.products as p', 'p.id', '=', 'cs.product_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'cs.warehouse_id')
            ->select([
                'cs.company_id',
                'cs.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'cs.product_id',
                'p.sku',
                'p.name as product_name',
                'cs.stock',
            ])
            ->where('cs.company_id', $companyId)
            ->orderBy('p.name');

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('cs.warehouse_id', (int) $warehouseId);
        }

        if ($productId !== null && $productId !== '') {
            $query->where('cs.product_id', (int) $productId);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function lots(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $warehouseId = $request->query('warehouse_id');
        $productId = $request->query('product_id');
        $onlyWithStock = filter_var($request->query('only_with_stock', true), FILTER_VALIDATE_BOOLEAN);

        $query = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'pl.warehouse_id')
            ->leftJoin('inventory.current_stock_by_lot as sl', function ($join) {
                $join->on('sl.lot_id', '=', 'pl.id')
                    ->on('sl.product_id', '=', 'pl.product_id')
                    ->on('sl.warehouse_id', '=', 'pl.warehouse_id')
                    ->on('sl.company_id', '=', 'pl.company_id');
            })
            ->select([
                'pl.id',
                'pl.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'pl.product_id',
                'p.sku',
                'p.name as product_name',
                'pl.lot_code',
                'pl.manufacture_at',
                'pl.expires_at',
                'pl.received_at',
                'pl.status',
                DB::raw('COALESCE(sl.stock, 0) as stock'),
            ])
            ->where('pl.company_id', $companyId)
            ->orderBy('p.name')
            ->orderBy('pl.lot_code');

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('pl.warehouse_id', (int) $warehouseId);
        }

        if ($productId !== null && $productId !== '') {
            $query->where('pl.product_id', (int) $productId);
        }

        if ($onlyWithStock) {
            $query->whereRaw('COALESCE(sl.stock, 0) > 0');
        }

        return response()->json([
            'data' => $query->get(),
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

        $this->ensureStockEntriesTables();

        $summarySubquery = DB::table('inventory.stock_entry_items')
            ->selectRaw('entry_id, COUNT(*) as total_items, COALESCE(SUM(qty), 0) as total_qty, COALESCE(SUM(qty * unit_cost), 0) as total_amount')
            ->groupBy('entry_id');

        $query = DB::table('inventory.stock_entries as e')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'e.warehouse_id')
            ->leftJoinSub($summarySubquery, 's', function ($join) {
                $join->on('s.entry_id', '=', 'e.id');
            })
            ->select([
                'e.id',
                'e.company_id',
                'e.branch_id',
                'e.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'e.entry_type',
                'e.reference_no',
                'e.supplier_reference',
                'e.issue_at',
                'e.status',
                'e.notes',
                DB::raw('COALESCE(s.total_items, 0) as total_items'),
                DB::raw('COALESCE(s.total_qty, 0) as total_qty'),
                DB::raw('COALESCE(s.total_amount, 0) as total_amount'),
                'e.created_at',
            ])
            ->where('e.company_id', $companyId)
            ->orderByDesc('e.issue_at')
            ->orderByDesc('e.id')
            ->limit($limit);

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('e.warehouse_id', (int) $warehouseId);
        }

        if ($entryType !== null && $entryType !== '') {
            $query->where('e.entry_type', strtoupper((string) $entryType));
        }

        return response()->json([
            'data' => $query->get(),
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

        $query = DB::table('inventory.inventory_ledger as il')
            ->leftJoin('inventory.products as p', 'p.id', '=', 'il.product_id')
            ->leftJoin('inventory.warehouses as w', 'w.id', '=', 'il.warehouse_id')
            ->leftJoin('inventory.product_lots as pl', 'pl.id', '=', 'il.lot_id')
            ->select([
                'il.id',
                'il.warehouse_id',
                DB::raw('w.code as warehouse_code'),
                DB::raw('w.name as warehouse_name'),
                'il.product_id',
                DB::raw('p.sku as product_sku'),
                DB::raw('p.name as product_name'),
                'il.lot_id',
                DB::raw('pl.lot_code'),
                'il.movement_type',
                'il.quantity',
                'il.unit_cost',
                DB::raw('(il.quantity * il.unit_cost) as line_total'),
                'il.ref_type',
                'il.ref_id',
                'il.notes',
                'il.moved_at',
            ])
            ->where('il.company_id', $companyId)
            ->orderByDesc('il.moved_at')
            ->orderByDesc('il.id')
            ->limit($limit);

        if ($productId !== null && $productId !== '') {
            $query->where('il.product_id', (int) $productId);
        }
        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('il.warehouse_id', (int) $warehouseId);
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where('il.moved_at', '>=', $dateFrom);
        }
        if ($dateTo !== null && $dateTo !== '') {
            $query->where('il.moved_at', '<=', $dateTo . ' 23:59:59');
        }

        return response()->json(['data' => $query->get()]);
    }

    public function createStockEntry(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'entry_type' => 'required|string|in:PURCHASE,ADJUSTMENT',
            'reference_no' => 'nullable|string|max:60',
            'supplier_reference' => 'nullable|string|max:120',
            'issue_at' => 'nullable|date',
            'notes' => 'nullable|string|max:300',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty' => 'required|numeric',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.lot_id' => 'nullable|integer|min:1',
            'items.*.lot_code' => 'nullable|string|max:80',
            'items.*.manufacture_at' => 'nullable|date',
            'items.*.expires_at' => 'nullable|date',
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

        $warehouseExists = DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', (int) $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->exists();

        if (!$warehouseExists) {
            return response()->json(['message' => 'Invalid warehouse scope'], 422);
        }

        $this->ensureStockEntriesTables();

        $productIds = collect($payload['items'])
            ->pluck('product_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->unique()
            ->values();

        $products = DB::table('inventory.products')
            ->select('id', 'name', 'is_stockable', 'lot_tracking', 'status')
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds->all())
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $settings = $this->inventorySettingsForCompany($companyId);

        try {
            $result = DB::transaction(function () use ($payload, $authUser, $companyId, $branchId, $warehouseId, $products, $settings) {
                $entryType = strtoupper((string) $payload['entry_type']);

                $entryId = DB::table('inventory.stock_entries')->insertGetId([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                    'entry_type' => $entryType,
                    'reference_no' => $payload['reference_no'] ?? null,
                    'supplier_reference' => $payload['supplier_reference'] ?? null,
                    'issue_at' => $payload['issue_at'] ?? now(),
                    'status' => 'APPLIED',
                    'notes' => $payload['notes'] ?? null,
                    'created_by' => $authUser->id,
                    'updated_by' => $authUser->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($payload['items'] as $index => $item) {
                    $productId = (int) $item['product_id'];
                    $product = $products->get($productId);

                    if (!$product) {
                        throw new \RuntimeException('Product not found for line ' . ($index + 1));
                    }

                    if ((int) $product->status !== 1) {
                        throw new \RuntimeException('Product inactive for line ' . ($index + 1));
                    }

                    if (!(bool) $product->is_stockable) {
                        throw new \RuntimeException('Product is not stockable for line ' . ($index + 1));
                    }

                    $qty = round((float) $item['qty'], 8);

                    if ($entryType === 'PURCHASE' && $qty <= 0) {
                        throw new \RuntimeException('Purchase line quantity must be positive for line ' . ($index + 1));
                    }

                    if ($entryType === 'ADJUSTMENT' && abs($qty) < 0.00000001) {
                        throw new \RuntimeException('Adjustment line quantity cannot be zero for line ' . ($index + 1));
                    }

                    $lotId = isset($item['lot_id']) ? (int) $item['lot_id'] : null;
                    $lotCode = isset($item['lot_code']) ? trim((string) $item['lot_code']) : null;

                    if ((bool) $product->lot_tracking && (bool) $settings['enforce_lot_for_tracked'] && !$lotId && !$lotCode) {
                        throw new \RuntimeException('Lot is required for tracked product line ' . ($index + 1));
                    }

                    if ($lotId) {
                        $lotExists = DB::table('inventory.product_lots')
                            ->where('id', $lotId)
                            ->where('company_id', $companyId)
                            ->where('warehouse_id', $warehouseId)
                            ->where('product_id', $productId)
                            ->where('status', 1)
                            ->exists();

                        if (!$lotExists) {
                            throw new \RuntimeException('Lot not found for line ' . ($index + 1));
                        }
                    }

                    if (!$lotId && $lotCode !== null && $lotCode !== '') {
                        $lotId = DB::table('inventory.product_lots')->insertGetId([
                            'company_id' => $companyId,
                            'warehouse_id' => $warehouseId,
                            'product_id' => $productId,
                            'lot_code' => $lotCode,
                            'manufacture_at' => $item['manufacture_at'] ?? null,
                            'expires_at' => $item['expires_at'] ?? null,
                            'received_at' => $payload['issue_at'] ?? now(),
                            'status' => 1,
                            'created_by' => $authUser->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $movementType = $entryType === 'PURCHASE' ? 'IN' : ($qty >= 0 ? 'IN' : 'OUT');

                    $unitCost = isset($item['unit_cost']) ? (float) $item['unit_cost'] : 0.0;

                    $this->applyCurrentStockDelta(
                        $companyId,
                        $warehouseId,
                        $productId,
                        $qty,
                        (bool) $settings['allow_negative_stock']
                    );

                    if ($lotId) {
                        $this->applyLotStockDelta(
                            $companyId,
                            $warehouseId,
                            $productId,
                            (int) $lotId,
                            $qty,
                            (bool) $settings['allow_negative_stock']
                        );
                    }

                    DB::table('inventory.stock_entry_items')->insert([
                        'entry_id' => $entryId,
                        'product_id' => $productId,
                        'lot_id' => $lotId,
                        'qty' => $qty,
                        'unit_cost' => $unitCost,
                        'notes' => $item['notes'] ?? null,
                        'created_at' => now(),
                    ]);

                    DB::table('inventory.inventory_ledger')->insert([
                        'company_id' => $companyId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'lot_id' => $lotId,
                        'movement_type' => $movementType,
                        'quantity' => round(abs($qty), 8),
                        'unit_cost' => $unitCost,
                        'ref_type' => 'STOCK_ENTRY',
                        'ref_id' => $entryId,
                        'notes' => $payload['notes'] ?? null,
                        'moved_at' => $payload['issue_at'] ?? now(),
                        'created_by' => $authUser->id,
                    ]);

                    if ($entryType === 'PURCHASE' && $unitCost > 0) {
                        DB::table('inventory.products')
                            ->where('id', $productId)
                            ->where('company_id', $companyId)
                            ->update([
                                'cost_price' => $unitCost,
                            ]);
                    }
                }

                return [
                    'id' => (int) $entryId,
                    'entry_type' => $entryType,
                    'warehouse_id' => $warehouseId,
                    'status' => 'APPLIED',
                    'items' => count($payload['items']),
                ];
            });
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

        $product = DB::table('inventory.products')
            ->select('id', 'company_id', 'name', 'unit_id', 'sale_price')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $this->ensureProductSaleUnitsTable();
        $this->ensureProductPriceTierValuesTable();

        $features = $this->commerceFeatures($companyId);

        $enabledUnits = DB::table('core.units as u')
            ->join('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId)
                    ->where('cu.is_enabled', '=', true);
            })
            ->select('u.id', 'u.code', 'u.name', 'u.sunat_uom_code')
            ->orderBy('u.name')
            ->get();

        $productUnits = DB::table('inventory.product_sale_units as pu')
            ->join('core.units as u', 'u.id', '=', 'pu.unit_id')
            ->select([
                'pu.unit_id',
                'pu.is_base',
                'pu.status',
                'u.code',
                'u.name',
                'u.sunat_uom_code',
            ])
            ->where('pu.company_id', $companyId)
            ->where('pu.product_id', $id)
            ->orderByDesc('pu.is_base')
            ->orderBy('u.name')
            ->get();

        if ($productUnits->isEmpty() && $product->unit_id) {
            $baseUnit = DB::table('core.units')
                ->select('id as unit_id', 'code', 'name', 'sunat_uom_code')
                ->where('id', (int) $product->unit_id)
                ->first();

            if ($baseUnit) {
                $productUnits = collect([
                    [
                        'unit_id' => (int) $baseUnit->unit_id,
                        'is_base' => true,
                        'status' => 1,
                        'code' => $baseUnit->code,
                        'name' => $baseUnit->name,
                        'sunat_uom_code' => $baseUnit->sunat_uom_code,
                    ],
                ]);
            }
        }

        $conversions = DB::table('inventory.product_uom_conversions as c')
            ->join('core.units as fu', 'fu.id', '=', 'c.from_unit_id')
            ->join('core.units as tu', 'tu.id', '=', 'c.to_unit_id')
            ->select([
                'c.id',
                'c.from_unit_id',
                'fu.code as from_unit_code',
                'fu.name as from_unit_name',
                'c.to_unit_id',
                'tu.code as to_unit_code',
                'tu.name as to_unit_name',
                'c.conversion_factor',
                'c.status',
            ])
            ->where('c.company_id', $companyId)
            ->where('c.product_id', $id)
            ->orderBy('fu.name')
            ->get();

        $wholesalePrices = DB::table('sales.product_price_tier_values as ptv')
            ->join('sales.price_tiers as pt', 'pt.id', '=', 'ptv.price_tier_id')
            ->leftJoin('core.units as u', 'u.id', '=', 'ptv.unit_id')
            ->select([
                'ptv.id',
                'ptv.price_tier_id',
                'pt.code as tier_code',
                'pt.name as tier_name',
                'pt.min_qty',
                'pt.max_qty',
                'ptv.unit_id',
                'u.code as unit_code',
                'u.name as unit_name',
                'ptv.unit_price',
                'ptv.status',
            ])
            ->where('ptv.company_id', $companyId)
            ->where('ptv.product_id', $id)
            ->where('pt.status', 1)
            ->orderBy('pt.priority')
            ->orderBy('pt.min_qty')
            ->get();

        $priceTiers = DB::table('sales.price_tiers')
            ->select('id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('min_qty')
            ->get();

        return response()->json([
            'product' => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'unit_id' => $product->unit_id ? (int) $product->unit_id : null,
                'sale_price' => (float) $product->sale_price,
            ],
            'features' => $features,
            'enabled_units' => $enabledUnits,
            'product_units' => $productUnits,
            'conversions' => $conversions,
            'price_tiers' => $priceTiers,
            'wholesale_prices' => $wholesalePrices,
        ]);
    }

    public function updateProductCommercialConfig(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
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

        $exists = DB::table('inventory.products')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $payload = $validator->validated();

        $this->ensureProductSaleUnitsTable();
        $this->ensureProductPriceTierValuesTable();

        DB::transaction(function () use ($payload, $companyId, $id, $authUser) {
            if (array_key_exists('base_unit_id', $payload)) {
                DB::table('inventory.products')
                    ->where('id', $id)
                    ->where('company_id', $companyId)
                    ->update([
                        'unit_id' => $payload['base_unit_id'],
                    ]);
            }

            if (array_key_exists('units', $payload)) {
                DB::table('inventory.product_sale_units')
                    ->where('company_id', $companyId)
                    ->where('product_id', $id)
                    ->delete();

                foreach ($payload['units'] as $row) {
                    DB::table('inventory.product_sale_units')->insert([
                        'company_id' => $companyId,
                        'product_id' => $id,
                        'unit_id' => (int) $row['unit_id'],
                        'is_base' => (bool) ($row['is_base'] ?? false),
                        'status' => (int) ($row['status'] ?? 1),
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('conversions', $payload)) {
                DB::table('inventory.product_uom_conversions')
                    ->where('company_id', $companyId)
                    ->where('product_id', $id)
                    ->delete();

                foreach ($payload['conversions'] as $row) {
                    DB::table('inventory.product_uom_conversions')->insert([
                        'company_id' => $companyId,
                        'product_id' => $id,
                        'from_unit_id' => (int) $row['from_unit_id'],
                        'to_unit_id' => (int) $row['to_unit_id'],
                        'conversion_factor' => $row['conversion_factor'],
                        'status' => (int) ($row['status'] ?? 1),
                        'created_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('wholesale_prices', $payload)) {
                DB::table('sales.product_price_tier_values')
                    ->where('company_id', $companyId)
                    ->where('product_id', $id)
                    ->delete();

                foreach ($payload['wholesale_prices'] as $row) {
                    DB::table('sales.product_price_tier_values')->insert([
                        'company_id' => $companyId,
                        'product_id' => $id,
                        'price_tier_id' => (int) $row['price_tier_id'],
                        'unit_id' => isset($row['unit_id']) ? (int) $row['unit_id'] : null,
                        'unit_price' => $row['unit_price'],
                        'status' => (int) ($row['status'] ?? 1),
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $request->query->set('company_id', $companyId);

        return $this->productCommercialConfig($request, $id);
    }

    private function commerceFeatures(int $companyId): array
    {
        $rows = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', [
                self::FEATURE_MULTI_UOM,
                self::FEATURE_UOM_CONVERSIONS,
                self::FEATURE_WHOLESALE_PRICING,
            ])
            ->pluck('is_enabled', 'feature_code');

        return [
            self::FEATURE_MULTI_UOM => (bool) ($rows[self::FEATURE_MULTI_UOM] ?? false),
            self::FEATURE_UOM_CONVERSIONS => (bool) ($rows[self::FEATURE_UOM_CONVERSIONS] ?? false),
            self::FEATURE_WHOLESALE_PRICING => (bool) ($rows[self::FEATURE_WHOLESALE_PRICING] ?? false),
        ];
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return [
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => true,
            ];
        }

        return [
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
            'enforce_lot_for_tracked' => (bool) $row->enforce_lot_for_tracked,
        ];
    }

    private function applyCurrentStockDelta(
        int $companyId,
        int $warehouseId,
        int $productId,
        float $delta,
        bool $allowNegativeStock
    ): void {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;

        if (!array_key_exists($projectionKey, $this->stockProjection)) {
            $row = DB::table('inventory.current_stock')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();

            $this->stockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        $current = $this->stockProjection[$projectionKey];
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for product #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }

    private function applyLotStockDelta(
        int $companyId,
        int $warehouseId,
        int $productId,
        int $lotId,
        float $delta,
        bool $allowNegativeStock
    ): void {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;

        if (!array_key_exists($projectionKey, $this->lotStockProjection)) {
            $row = DB::table('inventory.current_stock_by_lot')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('lot_id', $lotId)
                ->first();

            $this->lotStockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        $current = $this->lotStockProjection[$projectionKey];
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for lot #' . $lotId);
        }

        $this->lotStockProjection[$projectionKey] = round($next, 8);
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

    private function ensureProductSaleUnitsTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.product_sale_units (
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                unit_id BIGINT NOT NULL,
                is_base BOOLEAN NOT NULL DEFAULT FALSE,
                status SMALLINT NOT NULL DEFAULT 1,
                updated_by BIGINT NULL,
                updated_at TIMESTAMPTZ NULL,
                PRIMARY KEY (company_id, product_id, unit_id)
            )'
        );
    }

    private function ensureProductPriceTierValuesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.product_price_tier_values (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                price_tier_id BIGINT NOT NULL,
                unit_id BIGINT NULL,
                unit_price NUMERIC(18,6) NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                updated_by BIGINT NULL,
                updated_at TIMESTAMPTZ NULL,
                UNIQUE(company_id, product_id, price_tier_id, unit_id)
            )'
        );
    }

    private function ensureStockEntriesTables(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.stock_entries (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                branch_id BIGINT NULL,
                warehouse_id BIGINT NOT NULL,
                entry_type VARCHAR(20) NOT NULL,
                reference_no VARCHAR(60) NULL,
                supplier_reference VARCHAR(120) NULL,
                issue_at TIMESTAMPTZ NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'APPLIED\',
                notes VARCHAR(300) NULL,
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                created_at TIMESTAMPTZ NULL,
                updated_at TIMESTAMPTZ NULL
            )'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS stock_entries_company_issue_idx
                ON inventory.stock_entries (company_id, issue_at DESC, id DESC)'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.stock_entry_items (
                id BIGSERIAL PRIMARY KEY,
                entry_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                lot_id BIGINT NULL,
                qty NUMERIC(18,8) NOT NULL,
                unit_cost NUMERIC(18,8) NOT NULL DEFAULT 0,
                notes VARCHAR(200) NULL,
                created_at TIMESTAMPTZ NULL
            )'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS stock_entry_items_entry_idx
                ON inventory.stock_entry_items (entry_id)'
        );
    }
}
