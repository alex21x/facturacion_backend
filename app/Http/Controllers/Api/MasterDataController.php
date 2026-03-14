<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MasterDataController extends Controller
{
    private const DOCUMENT_KIND_CATALOG = [
        ['code' => 'QUOTATION', 'label' => 'Cotizacion'],
        ['code' => 'SALES_ORDER', 'label' => 'Pedido de Venta'],
        ['code' => 'INVOICE', 'label' => 'Factura'],
        ['code' => 'RECEIPT', 'label' => 'Boleta'],
        ['code' => 'CREDIT_NOTE', 'label' => 'Nota de Credito'],
        ['code' => 'DEBIT_NOTE', 'label' => 'Nota de Debito'],
    ];

    public function options(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $branches = DB::table('core.branches')
            ->select('id', 'code', 'name')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $warehouses = DB::table('inventory.warehouses')
            ->select('id', 'branch_id', 'code', 'name')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('name')
            ->get();

        $products = DB::table('inventory.products')
            ->select('id', 'sku', 'name')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->orderBy('name')
            ->limit(200)
            ->get();

        return response()->json([
            'branches' => $branches,
            'warehouses' => $warehouses,
            'products' => $products,
        ]);
    }

    public function dashboard(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $units = $this->companyUnits($companyId);

        $options = [
            'branches' => DB::table('core.branches')
                ->select('id', 'code', 'name')
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->orderByDesc('is_main')
                ->orderBy('name')
                ->get(),
            'warehouses' => DB::table('inventory.warehouses')
                ->select('id', 'branch_id', 'code', 'name')
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->orderBy('name')
                ->get(),
            'products' => DB::table('inventory.products')
                ->select('id', 'sku', 'name')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->orderBy('name')
                ->limit(200)
                ->get(),
        ];

        $warehouses = DB::table('inventory.warehouses')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'address', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $cashRegisters = DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $paymentMethods = DB::table('core.payment_methods')
            ->select('id', 'code', 'name', 'status')
            ->orderBy('name')
            ->get();

        $series = DB::table('sales.series_numbers')
            ->select([
                'id',
                'company_id',
                'branch_id',
                'warehouse_id',
                'document_kind',
                'series',
                'current_number',
                'number_padding',
                'reset_policy',
                'is_enabled',
            ])
            ->where('company_id', $companyId)
            ->orderBy('document_kind')
            ->orderBy('series')
            ->get();

        $lots = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'pl.warehouse_id')
            ->select([
                'pl.id',
                'pl.product_id',
                'p.name as product_name',
                'pl.warehouse_id',
                'w.name as warehouse_name',
                'pl.lot_code',
                'pl.manufacture_at',
                'pl.expires_at',
                'pl.unit_cost',
                'pl.status',
            ])
            ->where('pl.company_id', $companyId)
            ->orderByDesc('pl.received_at')
            ->limit(300)
            ->get();

        $inventorySettings = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$inventorySettings) {
            $inventorySettings = [
                'company_id' => $companyId,
                'inventory_mode' => 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => 'MANUAL',
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => true,
            ];
        }

        $toggles = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', $this->documentKindFeatureCodes())
            ->pluck('is_enabled', 'feature_code');

        $documentKinds = collect(self::DOCUMENT_KIND_CATALOG)->map(function ($row) use ($toggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];

            return [
                'code' => $row['code'],
                'label' => $row['label'],
                'feature_code' => $featureCode,
                'is_enabled' => $toggles->has($featureCode) ? (bool) $toggles->get($featureCode) : true,
            ];
        })->values();

        return response()->json([
            'options' => $options,
            'warehouses' => $warehouses,
            'cash_registers' => $cashRegisters,
            'payment_methods' => $paymentMethods,
            'series' => $series,
            'lots' => $lots,
            'units' => $units,
            'inventory_settings' => $inventorySettings,
            'document_kinds' => $documentKinds,
            'stats' => [
                'warehouses_total' => $warehouses->count(),
                'cash_registers_total' => $cashRegisters->count(),
                'payment_methods_total' => $paymentMethods->count(),
                'series_total' => $series->count(),
                'lots_total' => $lots->count(),
                'units_enabled_total' => $units->where('is_enabled', true)->count(),
            ],
        ]);
    }

    public function units(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        return response()->json([
            'data' => $this->companyUnits($companyId),
        ]);
    }

    public function warehouses(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = DB::table('inventory.warehouses')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'address', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function createWarehouse(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:120',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        if (!empty($payload['branch_id'])) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $id = DB::table('inventory.warehouses')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'code' => strtoupper(trim($payload['code'])),
            'name' => trim($payload['name']),
            'address' => $payload['address'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
        ]);

        return response()->json(['message' => 'Warehouse created', 'id' => (int) $id], 201);
    }

    public function updateWarehouse(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('inventory.warehouses')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Warehouse not found'], 404);
        }

        $payload = $validator->validated();
        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $updates = [];
        if (array_key_exists('branch_id', $payload)) {
            $updates['branch_id'] = $payload['branch_id'];
        }
        if (!empty($payload['code'])) {
            $updates['code'] = strtoupper(trim($payload['code']));
        }
        if (!empty($payload['name'])) {
            $updates['name'] = trim($payload['name']);
        }
        if (array_key_exists('address', $payload)) {
            $updates['address'] = $payload['address'];
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            DB::table('inventory.warehouses')->where('id', $id)->update($updates);
        }

        return response()->json(['message' => 'Warehouse updated']);
    }

    public function cashRegisters(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function createCashRegister(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        if (!empty($payload['branch_id'])) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $id = DB::table('sales.cash_registers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'code' => strtoupper(trim($payload['code'])),
            'name' => trim($payload['name']),
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Cash register created', 'id' => (int) $id], 201);
    }

    public function updateCashRegister(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('sales.cash_registers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Cash register not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

        if (array_key_exists('branch_id', $payload)) {
            $updates['branch_id'] = $payload['branch_id'];
        }
        if (!empty($payload['code'])) {
            $updates['code'] = strtoupper(trim($payload['code']));
        }
        if (!empty($payload['name'])) {
            $updates['name'] = trim($payload['name']);
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            DB::table('sales.cash_registers')->where('id', $id)->update($updates);
        }

        return response()->json(['message' => 'Cash register updated']);
    }

    public function paymentMethods()
    {
        $rows = DB::table('core.payment_methods')
            ->select('id', 'code', 'name', 'status')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function createPaymentMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $id = DB::table('core.payment_methods')->insertGetId([
            'code' => strtoupper(trim($payload['code'])),
            'name' => trim($payload['name']),
            'status' => (int) ($payload['status'] ?? 1),
        ]);

        return response()->json(['message' => 'Payment method created', 'id' => (int) $id], 201);
    }

    public function updatePaymentMethod(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'nullable|string|max:20',
            'name' => 'nullable|string|max:100',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('core.payment_methods')->where('id', $id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Payment method not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

        if (!empty($payload['code'])) {
            $updates['code'] = strtoupper(trim($payload['code']));
        }
        if (!empty($payload['name'])) {
            $updates['name'] = trim($payload['name']);
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            DB::table('core.payment_methods')->where('id', $id)->update($updates);
        }

        return response()->json(['message' => 'Payment method updated']);
    }

    public function series(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = DB::table('sales.series_numbers')
            ->select([
                'id',
                'company_id',
                'branch_id',
                'warehouse_id',
                'document_kind',
                'series',
                'current_number',
                'number_padding',
                'reset_policy',
                'is_enabled',
            ])
            ->where('company_id', $companyId)
            ->orderBy('document_kind')
            ->orderBy('series')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function createSeries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'document_kind' => 'required|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
            'series' => 'required|string|max:10',
            'current_number' => 'nullable|integer|min:0',
            'number_padding' => 'nullable|integer|min:4|max:12',
            'reset_policy' => 'nullable|string|in:NONE,YEARLY,MONTHLY',
            'is_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $id = DB::table('sales.series_numbers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'warehouse_id' => $payload['warehouse_id'] ?? null,
            'document_kind' => $payload['document_kind'],
            'series' => strtoupper(trim($payload['series'])),
            'current_number' => (int) ($payload['current_number'] ?? 0),
            'number_padding' => (int) ($payload['number_padding'] ?? 8),
            'reset_policy' => $payload['reset_policy'] ?? 'NONE',
            'is_enabled' => array_key_exists('is_enabled', $payload) ? (bool) $payload['is_enabled'] : true,
            'updated_by' => $authUser->id,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Series created', 'id' => (int) $id], 201);
    }

    public function updateSeries(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'document_kind' => 'nullable|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
            'series' => 'nullable|string|max:10',
            'current_number' => 'nullable|integer|min:0',
            'number_padding' => 'nullable|integer|min:4|max:12',
            'reset_policy' => 'nullable|string|in:NONE,YEARLY,MONTHLY',
            'is_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('sales.series_numbers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Series not found'], 404);
        }

        $payload = $validator->validated();
        $updates = ['updated_by' => $authUser->id, 'updated_at' => now()];

        foreach (['branch_id', 'warehouse_id', 'document_kind', 'current_number', 'number_padding', 'reset_policy'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        if (!empty($payload['series'])) {
            $updates['series'] = strtoupper(trim($payload['series']));
        }

        if (array_key_exists('is_enabled', $payload)) {
            $updates['is_enabled'] = (bool) $payload['is_enabled'];
        }

        DB::table('sales.series_numbers')->where('id', $id)->update($updates);

        return response()->json(['message' => 'Series updated']);
    }

    public function inventorySettings(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            $row = [
                'company_id' => $companyId,
                'inventory_mode' => 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => 'MANUAL',
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => true,
            ];
        }

        return response()->json(['data' => $row]);
    }

    public function updateInventorySettings(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'inventory_mode' => 'nullable|string|in:KARDEX_SIMPLE,LOT_TRACKING',
            'lot_outflow_strategy' => 'nullable|string|in:MANUAL,FIFO,FEFO',
            'allow_negative_stock' => 'nullable|boolean',
            'enforce_lot_for_tracked' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $updates = ['updated_at' => now()];

        foreach (['inventory_mode', 'lot_outflow_strategy', 'allow_negative_stock', 'enforce_lot_for_tracked'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        DB::table('inventory.inventory_settings')->updateOrInsert(
            ['company_id' => $companyId],
            $updates
        );

        return response()->json(['message' => 'Inventory settings updated']);
    }

    public function lots(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $productId = $request->query('product_id');
        $warehouseId = $request->query('warehouse_id');

        $query = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'pl.warehouse_id')
            ->select([
                'pl.id',
                'pl.product_id',
                'p.name as product_name',
                'pl.warehouse_id',
                'w.name as warehouse_name',
                'pl.lot_code',
                'pl.manufacture_at',
                'pl.expires_at',
                'pl.unit_cost',
                'pl.status',
            ])
            ->where('pl.company_id', $companyId)
            ->orderByDesc('pl.received_at');

        if ($productId !== null && $productId !== '') {
            $query->where('pl.product_id', (int) $productId);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('pl.warehouse_id', (int) $warehouseId);
        }

        return response()->json(['data' => $query->limit(300)->get()]);
    }

    public function createLot(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'lot_code' => 'required|string|max:60',
            'manufacture_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier_reference' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $productExists = DB::table('inventory.products')
            ->where('id', (int) $payload['product_id'])
            ->where('company_id', $companyId)
            ->exists();

        $warehouseExists = DB::table('inventory.warehouses')
            ->where('id', (int) $payload['warehouse_id'])
            ->where('company_id', $companyId)
            ->exists();

        if (!$productExists || !$warehouseExists) {
            return response()->json(['message' => 'Invalid product or warehouse scope'], 422);
        }

        $id = DB::table('inventory.product_lots')->insertGetId([
            'company_id' => $companyId,
            'warehouse_id' => (int) $payload['warehouse_id'],
            'product_id' => (int) $payload['product_id'],
            'lot_code' => strtoupper(trim($payload['lot_code'])),
            'manufacture_at' => $payload['manufacture_at'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,
            'unit_cost' => $payload['unit_cost'] ?? null,
            'supplier_reference' => $payload['supplier_reference'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
            'created_by' => $authUser->id,
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Lot created', 'id' => (int) $id], 201);
    }

    public function documentKinds(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $toggles = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', $this->documentKindFeatureCodes())
            ->pluck('is_enabled', 'feature_code');

        $rows = collect(self::DOCUMENT_KIND_CATALOG)->map(function ($row) use ($toggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];

            return [
                'code' => $row['code'],
                'label' => $row['label'],
                'feature_code' => $featureCode,
                'is_enabled' => $toggles->has($featureCode) ? (bool) $toggles->get($featureCode) : true,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    public function updateDocumentKinds(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'kinds' => 'required|array|min:1',
            'kinds.*.code' => 'required|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
            'kinds.*.is_enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $items = $validator->validated()['kinds'];

        foreach ($items as $item) {
            DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'feature_code' => 'DOC_KIND_' . $item['code'],
                ],
                [
                    'is_enabled' => (bool) $item['is_enabled'],
                    'config' => json_encode(['managed_by' => 'masters']),
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Document kinds updated']);
    }

    public function updateUnits(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'units' => 'required|array|min:1',
            'units.*.id' => 'required|integer|min:1',
            'units.*.is_enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $items = $validator->validated()['units'];
        $unitIds = collect($items)->pluck('id')->map(function ($value) {
            return (int) $value;
        })->unique()->values();

        $existingIds = DB::table('core.units')
            ->whereIn('id', $unitIds)
            ->pluck('id')
            ->map(function ($value) {
                return (int) $value;
            })
            ->values();

        if ($existingIds->count() !== $unitIds->count()) {
            return response()->json(['message' => 'One or more unit ids are invalid'], 422);
        }

        $this->ensureCompanyUnitsTable();

        foreach ($items as $item) {
            DB::table('appcfg.company_units')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'unit_id' => (int) $item['id'],
                ],
                [
                    'is_enabled' => (bool) $item['is_enabled'],
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Units updated']);
    }

    private function companyUnits(int $companyId)
    {
        $this->ensureCompanyUnitsTable();

        return DB::table('core.units as u')
            ->leftJoin('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select([
                'u.id',
                'u.code',
                'u.sunat_uom_code',
                'u.name',
                DB::raw('COALESCE(cu.is_enabled, false) as is_enabled'),
            ])
            ->orderBy('u.name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'code' => $row->code,
                    'sunat_uom_code' => $row->sunat_uom_code,
                    'name' => trim((string) $row->name),
                    'is_enabled' => (bool) $row->is_enabled,
                ];
            })
            ->values();
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

    private function resolveCompanyId(Request $request): int
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            throw new HttpResponseException(response()->json(['message' => 'Invalid company scope'], 403));
        }

        return $companyId;
    }

    private function documentKindFeatureCodes(): array
    {
        return collect(self::DOCUMENT_KIND_CATALOG)
            ->map(function ($row) {
                return 'DOC_KIND_' . $row['code'];
            })
            ->values()
            ->all();
    }
}
