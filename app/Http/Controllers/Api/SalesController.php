<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalesController extends Controller
{    
    private $stockProjection = [];
    private $lotStockProjection = [];

    public function lookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $currencies = DB::table('core.currencies')
            ->select('id', 'code', 'name', 'symbol', 'is_default')
            ->where('status', 1)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $paymentMethods = DB::table('core.payment_methods')
            ->select('id', 'code', 'name')
            ->where('status', 1)
            ->orderBy('name')
            ->get();

        $catalog = collect([
            ['code' => 'QUOTATION', 'label' => 'Cotizacion'],
            ['code' => 'SALES_ORDER', 'label' => 'Pedido de Venta'],
            ['code' => 'INVOICE', 'label' => 'Factura'],
            ['code' => 'RECEIPT', 'label' => 'Boleta'],
            ['code' => 'CREDIT_NOTE', 'label' => 'Nota de Credito'],
            ['code' => 'DEBIT_NOTE', 'label' => 'Nota de Debito'],
        ]);

        $featureCodes = $catalog->map(function ($row) {
            return 'DOC_KIND_' . $row['code'];
        })->values()->all();

        $enabledToggles = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', $featureCodes)
            ->pluck('is_enabled', 'feature_code');

        $documentKinds = $catalog->filter(function ($row) use ($enabledToggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];

            return !$enabledToggles->has($featureCode) || (bool) $enabledToggles->get($featureCode);
        })->values();

        return response()->json([
            'document_kinds' => $documentKinds,
            'currencies' => $currencies,
            'payment_methods' => $paymentMethods,
            'tax_categories' => $this->resolveTaxCategories($companyId),
            'units' => $this->enabledUnits($companyId),
        ]);
    }

    public function customerAutocomplete(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 12);

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 30) {
            $limit = 30;
        }

        $query = DB::table('sales.customers as c')
            ->select([
                'c.id',
                'c.doc_type',
                'c.doc_number',
                'c.legal_name',
                'c.trade_name',
                'c.first_name',
                'c.last_name',
                'c.plate',
                'c.address',
            ])
            ->where('c.company_id', $companyId)
            ->where('c.status', 1)
            ->orderBy('c.legal_name')
            ->limit($limit);

        if ($search !== '') {
            $query->where(function ($nested) use ($search) {
                $nested->where('c.doc_number', 'like', '%' . $search . '%')
                    ->orWhere('c.legal_name', 'like', '%' . $search . '%')
                    ->orWhere('c.trade_name', 'like', '%' . $search . '%')
                    ->orWhere('c.first_name', 'like', '%' . $search . '%')
                    ->orWhere('c.last_name', 'like', '%' . $search . '%')
                    ->orWhere('c.plate', 'like', '%' . $search . '%');
            });
        }

        $rows = $query->get()->map(function ($row) {
            $name = $row->legal_name;

            if (!$name) {
                $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));
            }

            return [
                'id' => (int) $row->id,
                'doc_type' => $row->doc_type,
                'doc_number' => $row->doc_number,
                'name' => $name ?: ('Cliente #' . $row->id),
                'trade_name' => $row->trade_name,
                'plate' => $row->plate,
                'address' => $row->address,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function customers(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $limit = (int) $request->query('limit', 100);

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 300) {
            $limit = 300;
        }

        $query = DB::table('sales.customers as c')
            ->select([
                'c.id',
                'c.doc_type',
                'c.doc_number',
                'c.legal_name',
                'c.trade_name',
                'c.first_name',
                'c.last_name',
                'c.plate',
                'c.address',
                'c.status',
            ])
            ->where('c.company_id', $companyId)
            ->orderBy('c.legal_name')
            ->limit($limit);

        if ($search !== '') {
            $query->where(function ($nested) use ($search) {
                $nested->where('c.doc_number', 'like', '%' . $search . '%')
                    ->orWhere('c.legal_name', 'like', '%' . $search . '%')
                    ->orWhere('c.trade_name', 'like', '%' . $search . '%')
                    ->orWhere('c.first_name', 'like', '%' . $search . '%')
                    ->orWhere('c.last_name', 'like', '%' . $search . '%')
                    ->orWhere('c.plate', 'like', '%' . $search . '%');
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('c.status', (int) $status);
        }

        $rows = $query->get()->map(function ($row) {
            $name = $row->legal_name;

            if (!$name) {
                $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));
            }

            return [
                'id' => (int) $row->id,
                'doc_type' => $row->doc_type,
                'doc_number' => $row->doc_number,
                'name' => $name ?: ('Cliente #' . $row->id),
                'trade_name' => $row->trade_name,
                'plate' => $row->plate,
                'address' => $row->address,
                'status' => (int) $row->status,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function createCustomer(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'doc_type' => 'nullable|string|max:20',
            'doc_number' => 'nullable|string|max:40',
            'legal_name' => 'nullable|string|max:180',
            'trade_name' => 'nullable|string|max:180',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'plate' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $id = DB::table('sales.customers')->insertGetId([
            'company_id' => $companyId,
            'doc_type' => $payload['doc_type'] ?? null,
            'doc_number' => $payload['doc_number'] ?? null,
            'legal_name' => $payload['legal_name'] ?? null,
            'trade_name' => $payload['trade_name'] ?? null,
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'plate' => $payload['plate'] ?? null,
            'address' => $payload['address'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
        ]);

        return response()->json(['message' => 'Customer created', 'id' => (int) $id], 201);
    }

    public function updateCustomer(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'doc_type' => 'nullable|string|max:20',
            'doc_number' => 'nullable|string|max:40',
            'legal_name' => 'nullable|string|max:180',
            'trade_name' => 'nullable|string|max:180',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'plate' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('sales.customers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $changes = $validator->validated();

        if (empty($changes)) {
            return response()->json(['message' => 'No changes provided'], 422);
        }

        DB::table('sales.customers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($changes);

        return response()->json(['message' => 'Customer updated']);
    }

    public function createCommercialDocument(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'nullable|integer|min:1',
            'document_kind' => 'required|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
            'series' => 'required|string|max:10',
            'issue_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'customer_id' => 'required|integer|min:1',
            'currency_id' => 'required|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'status' => 'nullable|string|in:DRAFT,APPROVED,ISSUED,VOID,CANCELED',
            'items' => 'required|array|min:1',
            'items.*.line_no' => 'nullable|integer|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.unit_id' => 'nullable|integer|min:1',
            'items.*.price_tier_id' => 'nullable|integer|min:1',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.qty_base' => 'nullable|numeric|min:0',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.00000001',
            'items.*.base_unit_price' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.wholesale_discount_percent' => 'nullable|numeric|min:0',
            'items.*.price_source' => 'nullable|string|in:MANUAL,TIER,PROFILE',
            'items.*.discount_total' => 'nullable|numeric|min:0',
            'items.*.tax_total' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            'items.*.total' => 'nullable|numeric|min:0',
            'items.*.metadata' => 'nullable|array',
            'items.*.lots' => 'nullable|array',
            'items.*.lots.*.lot_id' => 'required_with:items.*.lots|integer|min:1',
            'items.*.lots.*.qty' => 'required_with:items.*.lots|numeric|min:0.001',
            'payments' => 'nullable|array',
            'payments.*.payment_method_id' => 'required_with:payments|integer|min:1',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.due_at' => 'nullable|date',
            'payments.*.paid_at' => 'nullable|date',
            'payments.*.status' => 'nullable|string|in:PENDING,PAID,CANCELED',
            'payments.*.notes' => 'nullable|string|max:300',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);
        $branchId = array_key_exists('branch_id', $payload) ? $payload['branch_id'] : $authUser->branch_id;
        $warehouseId = $payload['warehouse_id'] ?? null;
        $cashRegisterId = $payload['cash_register_id'] ?? null;

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 422);
            }
        }

        if ($warehouseId !== null) {
            $warehouseExists = DB::table('inventory.warehouses')
                ->where('id', (int) $warehouseId)
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
                return response()->json([
                    'message' => 'Invalid warehouse scope',
                ], 422);
            }
        }

        if ($cashRegisterId !== null) {
            $cashRegisterExists = DB::table('sales.cash_registers')
                ->where('id', (int) $cashRegisterId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->where(function ($nested) use ($branchId) {
                        $nested->where('branch_id', (int) $branchId)
                            ->orWhereNull('branch_id');
                    });
                })
                ->exists();

            if (!$cashRegisterExists) {
                return response()->json([
                    'message' => 'Invalid cash register scope',
                ], 422);
            }
        }

        try {
            $result = DB::transaction(function () use ($payload, $authUser, $companyId, $branchId, $warehouseId, $cashRegisterId) {
                $isSellerToCashierMode = $this->isCommerceFeatureEnabled($companyId, 'SALES_SELLER_TO_CASHIER');
                $isPreDocument = in_array($payload['document_kind'], ['SALES_ORDER', 'QUOTATION'], true);
                $roleContext = $this->resolveAuthRoleContext((int) $authUser->id, $companyId);
                $roleCode = strtoupper((string) ($roleContext['role_code'] ?? ''));
                $roleProfile = strtoupper((string) ($roleContext['role_profile'] ?? ''));

                if ($isSellerToCashierMode) {
                    if ($isPreDocument && $this->isCashierActor($roleProfile, $roleCode)) {
                        throw new \RuntimeException('En este modo, caja no genera pedidos. Use Reporte para convertir pedidos pendientes.');
                    }

                    if (!$isPreDocument && $this->isSellerActor($roleProfile, $roleCode)) {
                        throw new \RuntimeException('En este modo, vendedor no emite comprobantes finales. Debe generar pedido para caja.');
                    }
                }

                if ($isSellerToCashierMode && $isPreDocument) {
                    $payload['status'] = 'DRAFT';
                    $payload['payments'] = [];
                    $cashRegisterId = null;
                }

                $documentStatus = $payload['status'] ?? 'DRAFT';
                $stockDirection = $this->stockDirectionForDocument($payload['document_kind']);
                $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                $stockAlreadyDiscounted = false;

                if (array_key_exists('stock_already_discounted', $metadata)) {
                    $stockAlreadyDiscounted = filter_var($metadata['stock_already_discounted'], FILTER_VALIDATE_BOOLEAN);
                }

                $affectsStock = !$stockAlreadyDiscounted && $this->shouldAffectStock($payload['document_kind'], $documentStatus);
                $settings = $this->inventorySettingsForCompany($companyId);

                $seriesRow = DB::table('sales.series_numbers')
                    ->where('company_id', $companyId)
                    ->where('document_kind', $payload['document_kind'])
                    ->where('series', $payload['series'])
                    ->where('is_enabled', true)
                    ->when($branchId !== null, function ($query) use ($branchId) {
                        return $query->where('branch_id', $branchId);
                    }, function ($query) {
                        return $query->whereNull('branch_id');
                    })
                    ->when($warehouseId !== null, function ($query) use ($warehouseId) {
                        return $query->where('warehouse_id', $warehouseId);
                    }, function ($query) {
                        return $query->whereNull('warehouse_id');
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$seriesRow) {
                    throw new \RuntimeException('No hay serie activa para ' . $payload['document_kind'] . ' en la sucursal/almacen seleccionado. Configurala en Maestros > Series.');
                }

                $nextNumber = ((int) $seriesRow->current_number) + 1;

                DB::table('sales.series_numbers')
                    ->where('id', $seriesRow->id)
                    ->update([
                        'current_number' => $nextNumber,
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]);

            $productIds = collect($payload['items'])
                ->pluck('product_id')
                ->filter(function ($id) {
                    return $id !== null;
                })
                ->map(function ($id) {
                    return (int) $id;
                })
                ->unique()
                ->values();

            $productMap = DB::table('inventory.products')
                ->select('id', 'name', 'unit_id', 'is_stockable', 'lot_tracking', 'status')
                ->where('company_id', $companyId)
                ->whereIn('id', $productIds->all())
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $allLotIds = collect($payload['items'])
                ->pluck('lots')
                ->filter(function ($lots) {
                    return is_array($lots) && !empty($lots);
                })
                ->flatten(1)
                ->pluck('lot_id')
                ->filter(function ($id) {
                    return $id !== null;
                })
                ->map(function ($id) {
                    return (int) $id;
                })
                ->unique()
                ->values();

            $lotMap = DB::table('inventory.product_lots')
                ->select('id', 'company_id', 'warehouse_id', 'product_id', 'status')
                ->where('company_id', $companyId)
                ->whereIn('id', $allLotIds->all())
                ->get()
                ->keyBy('id');

            $processedItems = [];

            foreach ($payload['items'] as $index => $item) {
                $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
                $product = $productId ? $productMap->get($productId) : null;

                if ($productId !== null && !$product) {
                    throw new \RuntimeException('Product not found for line ' . ($index + 1));
                }

                if ($productId !== null && (int) $product->status !== 1) {
                    throw new \RuntimeException('Product inactive for line ' . ($index + 1));
                }

                $itemUnitId = isset($item['unit_id']) ? (int) $item['unit_id'] : null;
                if ($product && !$itemUnitId) {
                    $itemUnitId = (int) $product->unit_id;
                }

                $conversion = $this->resolveLineConversion($companyId, $product, $item, $itemUnitId);
                $qtyBase = $conversion['qty_base'];
                $conversionFactor = $conversion['conversion_factor'];
                $baseUnitPrice = $conversion['base_unit_price'];

                $itemLots = [];
                $lotBaseQtyTotal = 0.0;

                if (!empty($item['lots']) && is_array($item['lots'])) {
                    foreach ($item['lots'] as $lot) {
                        $lotId = (int) $lot['lot_id'];
                        $lotQty = (float) $lot['qty'];
                        $lotBaseQty = $lotQty * $conversionFactor;
                        $lotRow = $lotMap->get($lotId);

                        if (!$lotRow) {
                            throw new \RuntimeException('Lot not found for line ' . ($index + 1));
                        }

                        if ($product && (int) $lotRow->product_id !== (int) $product->id) {
                            throw new \RuntimeException('Lot does not belong to product for line ' . ($index + 1));
                        }

                        if ($warehouseId !== null && (int) $lotRow->warehouse_id !== (int) $warehouseId) {
                            throw new \RuntimeException('Lot does not belong to warehouse scope for line ' . ($index + 1));
                        }

                        $itemLots[] = [
                            'lot_id' => $lotId,
                            'qty' => $lotQty,
                            'qty_base' => $lotBaseQty,
                        ];

                        $lotBaseQtyTotal += $lotBaseQty;
                    }

                    if (abs($lotBaseQtyTotal - $qtyBase) > 0.0001) {
                        throw new \RuntimeException('Lot quantity mismatch for line ' . ($index + 1));
                    }
                }

                if ($affectsStock && $product && (bool) $product->is_stockable) {
                    if ($warehouseId === null) {
                        throw new \RuntimeException('Warehouse is required for stockable product line ' . ($index + 1));
                    }

                    if ($stockDirection < 0 && (bool) $product->lot_tracking && (bool) $settings['enforce_lot_for_tracked'] && empty($itemLots)) {
                        throw new \RuntimeException('Lot is required for tracked product line ' . ($index + 1));
                    }
                }

                $processedItems[] = [
                    'raw' => $item,
                    'product' => $product,
                    'item_unit_id' => $itemUnitId,
                    'qty_base' => $qtyBase,
                    'conversion_factor' => $conversionFactor,
                    'base_unit_price' => $baseUnitPrice,
                    'lots' => $itemLots,
                    'should_apply_stock' => $affectsStock && $product && (bool) $product->is_stockable && $stockDirection !== 0,
                ];
            }

            $subtotal = 0.0;
            $taxTotal = 0.0;
            $discountTotal = 0.0;
            $grandTotal = 0.0;

            foreach ($payload['items'] as $item) {
                $itemSubtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : ((float) $item['qty'] * (float) $item['unit_price']);
                $itemTax = isset($item['tax_total']) ? (float) $item['tax_total'] : 0.0;
                $itemDiscount = isset($item['discount_total']) ? (float) $item['discount_total'] : 0.0;
                $itemTotal = isset($item['total']) ? (float) $item['total'] : ($itemSubtotal + $itemTax - $itemDiscount);

                $subtotal += $itemSubtotal;
                $taxTotal += $itemTax;
                $discountTotal += $itemDiscount;
                $grandTotal += $itemTotal;
            }

            $paidTotal = 0.0;
            if (!empty($payload['payments'])) {
                foreach ($payload['payments'] as $payment) {
                    if (($payment['status'] ?? 'PENDING') === 'PAID') {
                        $paidTotal += (float) $payment['amount'];
                    }
                }
            }

            $documentId = DB::table('sales.commercial_documents')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'document_kind' => $payload['document_kind'],
                'series' => $payload['series'],
                'number' => $nextNumber,
                'issue_at' => $payload['issue_at'] ?? now(),
                'due_at' => $payload['due_at'] ?? null,
                'customer_id' => $payload['customer_id'],
                'currency_id' => $payload['currency_id'],
                'payment_method_id' => $payload['payment_method_id'] ?? null,
                'exchange_rate' => $payload['exchange_rate'] ?? null,
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'total' => round($grandTotal, 2),
                'paid_total' => round($paidTotal, 2),
                'balance_due' => round($grandTotal - $paidTotal, 2),
                'discount_total' => round($discountTotal, 2),
                'status' => $documentStatus,
                'notes' => $payload['notes'] ?? null,
                'metadata' => json_encode(array_merge($payload['metadata'] ?? [], [
                    'cash_register_id' => $cashRegisterId !== null ? (int) $cashRegisterId : null,
                ])),
                'seller_user_id' => $authUser->id,
                'created_by' => $authUser->id,
                'updated_by' => $authUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $lineNo = 1;
            foreach ($processedItems as $processedItem) {
                $item = $processedItem['raw'];
                $itemSubtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : ((float) $item['qty'] * (float) $item['unit_price']);
                $itemTax = isset($item['tax_total']) ? (float) $item['tax_total'] : 0.0;
                $itemDiscount = isset($item['discount_total']) ? (float) $item['discount_total'] : 0.0;
                $itemTotal = isset($item['total']) ? (float) $item['total'] : ($itemSubtotal + $itemTax - $itemDiscount);

                $documentItemId = DB::table('sales.commercial_document_items')->insertGetId([
                    'document_id' => $documentId,
                    'line_no' => $item['line_no'] ?? $lineNo,
                    'product_id' => $item['product_id'] ?? null,
                    'unit_id' => $processedItem['item_unit_id'] ?? null,
                    'price_tier_id' => $item['price_tier_id'] ?? null,
                    'tax_category_id' => $item['tax_category_id'] ?? null,
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'qty_base' => round((float) $processedItem['qty_base'], 8),
                    'conversion_factor' => round((float) $processedItem['conversion_factor'], 8),
                    'base_unit_price' => round((float) $processedItem['base_unit_price'], 8),
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'] ?? 0,
                    'wholesale_discount_percent' => $item['wholesale_discount_percent'] ?? 0,
                    'price_source' => $item['price_source'] ?? 'MANUAL',
                    'discount_total' => round($itemDiscount, 2),
                    'tax_total' => round($itemTax, 2),
                    'subtotal' => round($itemSubtotal, 2),
                    'total' => round($itemTotal, 2),
                    'metadata' => isset($item['metadata']) ? json_encode($item['metadata']) : null,
                ]);

                if (!empty($processedItem['lots'])) {
                    foreach ($processedItem['lots'] as $lot) {
                        DB::table('sales.commercial_document_item_lots')->insert([
                            'document_item_id' => $documentItemId,
                            'lot_id' => $lot['lot_id'],
                            'qty' => $lot['qty'],
                            'created_at' => now(),
                        ]);
                    }
                }

                if ($processedItem['should_apply_stock']) {
                    $lineDeltaBase = $stockDirection * (float) $processedItem['qty_base'];
                    $product = $processedItem['product'];

                    $this->applyCurrentStockDelta(
                        $companyId,
                        (int) $warehouseId,
                        (int) $product->id,
                        $lineDeltaBase,
                        (bool) $settings['allow_negative_stock']
                    );

                    if (!empty($processedItem['lots'])) {
                        foreach ($processedItem['lots'] as $lot) {
                            $lotDeltaBase = $stockDirection * (float) $lot['qty_base'];

                            $this->applyLotStockDelta(
                                $companyId,
                                (int) $warehouseId,
                                (int) $product->id,
                                (int) $lot['lot_id'],
                                $lotDeltaBase,
                                (bool) $settings['allow_negative_stock']
                            );

                            DB::table('inventory.inventory_ledger')->insert([
                                'company_id' => $companyId,
                                'warehouse_id' => (int) $warehouseId,
                                'product_id' => (int) $product->id,
                                'lot_id' => (int) $lot['lot_id'],
                                'movement_type' => $stockDirection > 0 ? 'IN' : 'OUT',
                                'quantity' => round(abs($lotDeltaBase), 8),
                                'unit_cost' => $item['unit_cost'] ?? 0,
                                'ref_type' => 'COMMERCIAL_DOCUMENT',
                                'ref_id' => $documentId,
                                'notes' => 'Doc ' . $payload['document_kind'] . ' ' . $payload['series'] . '-' . $nextNumber,
                                'moved_at' => $payload['issue_at'] ?? now(),
                                'created_by' => $authUser->id,
                            ]);
                        }
                    } else {
                        DB::table('inventory.inventory_ledger')->insert([
                            'company_id' => $companyId,
                            'warehouse_id' => (int) $warehouseId,
                            'product_id' => (int) $product->id,
                            'lot_id' => null,
                            'movement_type' => $stockDirection > 0 ? 'IN' : 'OUT',
                            'quantity' => round(abs($lineDeltaBase), 8),
                            'unit_cost' => $item['unit_cost'] ?? 0,
                            'ref_type' => 'COMMERCIAL_DOCUMENT',
                            'ref_id' => $documentId,
                            'notes' => 'Doc ' . $payload['document_kind'] . ' ' . $payload['series'] . '-' . $nextNumber,
                            'moved_at' => $payload['issue_at'] ?? now(),
                            'created_by' => $authUser->id,
                        ]);
                    }
                }

                $lineNo++;
            }

            if (!empty($payload['payments'])) {
                foreach ($payload['payments'] as $payment) {
                    DB::table('sales.commercial_document_payments')->insert([
                        'document_id' => $documentId,
                        'payment_method_id' => $payment['payment_method_id'],
                        'amount' => $payment['amount'],
                        'due_at' => $payment['due_at'] ?? null,
                        'paid_at' => $payment['paid_at'] ?? null,
                        'status' => $payment['status'] ?? 'PENDING',
                        'notes' => $payment['notes'] ?? null,
                        'created_at' => now(),
                    ]);
                }
            }

            $this->registerCashIncomeFromDocument(
                $companyId,
                $branchId !== null ? (int) $branchId : null,
                $cashRegisterId !== null ? (int) $cashRegisterId : null,
                (int) $documentId,
                (string) $payload['document_kind'],
                (string) $payload['series'],
                (int) $nextNumber,
                (float) $paidTotal,
                (int) $authUser->id,
                $payload['payments'] ?? []
            );

            return [
                'id' => (int) $documentId,
                'document_kind' => $payload['document_kind'],
                'series' => $payload['series'],
                'number' => (int) $nextNumber,
                'total' => round($grandTotal, 2),
                'paid_total' => round($paidTotal, 2),
                'balance_due' => round($grandTotal - $paidTotal, 2),
                'status' => $documentStatus,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'cash_register_id' => $cashRegisterId,
            ];
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Commercial document created',
            'data' => $result,
        ], 201);
    }

    public function seriesNumbers(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id');
        $documentKind = $request->query('document_kind');
        $enabledOnly = filter_var($request->query('enabled_only', true), FILTER_VALIDATE_BOOLEAN);

        $query = DB::table('sales.series_numbers')
            ->where('company_id', $companyId)
            ->orderBy('document_kind')
            ->orderBy('series');

        if ($branchId !== null && $branchId !== '') {
            $query->where('branch_id', (int) $branchId);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        if ($documentKind) {
            $query->where('document_kind', $documentKind);
        }

        if ($enabledOnly) {
            $query->where('is_enabled', true);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function commercialDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $filters = [
            'branch_id' => $request->query('branch_id', $authUser->branch_id),
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'document_kind' => $request->query('document_kind'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
        ];
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('per_page', $request->query('limit', 10));

        if ($page < 1) {
            $page = 1;
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('core.payment_methods as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.company_id',
                'd.branch_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.created_at',
                'd.status',
                'd.external_status',
                'd.total',
                'd.balance_due',
                                DB::raw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) as source_document_id"),
                                DB::raw("(
                                        SELECT dsrc.document_kind
                                        FROM sales.commercial_documents dsrc
                                        WHERE dsrc.company_id = d.company_id
                                            AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                                        LIMIT 1
                                ) as source_document_kind"),
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                                DB::raw("EXISTS (
                                        SELECT 1
                                        FROM sales.commercial_documents d2
                                        WHERE d2.company_id = d.company_id
                                            AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                                            AND d2.status NOT IN ('VOID', 'CANCELED')
                                            AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                                ) as has_tributary_conversion"),
                                DB::raw("EXISTS (
                                                        SELECT 1
                                                        FROM sales.commercial_documents d3
                                                        WHERE d3.company_id = d.company_id
                                                            AND d3.document_kind = 'SALES_ORDER'
                                                            AND d3.status NOT IN ('VOID', 'CANCELED')
                                                            AND COALESCE((d3.metadata->>'source_document_id')::BIGINT, 0) = d.id
                                                ) as has_order_conversion"),
                                DB::raw("EXISTS (
                                                        SELECT 1
                                                        FROM sales.commercial_document_items di
                                                        WHERE di.document_id = d.id
                                                ) as has_items"),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
            ])
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        $total = (clone $query)->count('d.id');
        $lastPage = (int) max(1, ceil($total / $limit));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $rows = $query
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => (int) $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function exportCommercialDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $format = strtolower(trim((string) $request->query('format', 'csv')));
        $filters = [
            'branch_id' => $request->query('branch_id', $authUser->branch_id),
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'document_kind' => $request->query('document_kind'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
        ];

        $max = (int) $request->query('max', 5000);
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 20000) {
            $max = 20000;
        }

        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('core.payment_methods as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                'd.total',
                'd.balance_due',
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
            ])
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        $rows = $query
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->limit($max)
            ->get();

        if ($format === 'json') {
            return response()->json([
                'data' => $rows,
                'meta' => [
                    'count' => (int) $rows->count(),
                    'max' => $max,
                ],
            ]);
        }

        $filename = 'reporte_ventas_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID',
                'Documento',
                'Serie',
                'Numero',
                'Fecha Emision',
                'Cliente',
                'Forma de Pago',
                'Estado',
                'Total',
                'Saldo',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($out, [
                    (int) $row->id,
                    (string) $row->document_kind,
                    (string) $row->series,
                    (string) $row->number,
                    $row->issue_at ? (string) $row->issue_at : '',
                    (string) ($row->customer_name ?? ''),
                    (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                    (string) $row->status,
                    number_format((float) ($row->total ?? 0), 2, '.', ''),
                    number_format((float) ($row->balance_due ?? 0), 2, '.', ''),
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function applyCommercialDocumentFilters($query, array $filters): void
    {
        $branchId = $filters['branch_id'] ?? null;
        $warehouseId = $filters['warehouse_id'] ?? null;
        $cashRegisterId = $filters['cash_register_id'] ?? null;
        $documentKind = $filters['document_kind'] ?? null;
        $status = $filters['status'] ?? null;
        $conversionState = $filters['conversion_state'] ?? null;
        $customer = trim((string) ($filters['customer'] ?? ''));
        $issueDateFrom = $filters['issue_date_from'] ?? null;
        $issueDateTo = $filters['issue_date_to'] ?? null;
        $series = trim((string) ($filters['series'] ?? ''));
        $number = trim((string) ($filters['number'] ?? ''));

        if ($branchId !== null && $branchId !== '') {
            $query->where('d.branch_id', (int) $branchId);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('d.warehouse_id', (int) $warehouseId);
        }

        if ($cashRegisterId !== null && $cashRegisterId !== '') {
            $query->whereRaw("COALESCE((d.metadata->>'cash_register_id')::BIGINT, 0) = ?", [(int) $cashRegisterId]);
        }

        if ($documentKind) {
            $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $documentKind))));

            if (count($kinds) > 1) {
                $query->whereIn('d.document_kind', $kinds);
            } else {
                $query->where('d.document_kind', $kinds[0] ?? (string) $documentKind);
            }
        }

        if ($status) {
            $query->where('d.status', (string) $status);
        }

        if ($customer !== '') {
            $like = '%' . $customer . '%';
            $query->where(function ($nested) use ($like) {
                $nested->where('c.legal_name', 'ilike', $like)
                    ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) ILIKE ?", [$like])
                    ->orWhere('c.doc_number', 'ilike', $like);
            });
        }

        if ($issueDateFrom) {
            $query->whereDate('d.issue_at', '>=', $issueDateFrom);
        }

        if ($issueDateTo) {
            $query->whereDate('d.issue_at', '<=', $issueDateTo);
        }

        if ($series !== '') {
            $query->where('d.series', 'ilike', '%' . $series . '%');
        }

        if ($number !== '') {
            if (ctype_digit($number)) {
                $query->where('d.number', (int) $number);
            } else {
                $query->whereRaw('CAST(d.number AS TEXT) ILIKE ?', ['%' . $number . '%']);
            }
        }

        if ($conversionState === 'PENDING') {
            $query
                ->whereIn('d.document_kind', ['QUOTATION', 'SALES_ORDER'])
                ->whereRaw("NOT EXISTS (
                    SELECT 1
                    FROM sales.commercial_documents d2
                    WHERE d2.company_id = d.company_id
                      AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                      AND d2.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                )");
        }

        if ($conversionState === 'CONVERTED') {
            $query
                ->whereIn('d.document_kind', ['QUOTATION', 'SALES_ORDER'])
                ->whereRaw("EXISTS (
                    SELECT 1
                    FROM sales.commercial_documents d2
                    WHERE d2.company_id = d.company_id
                      AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                      AND d2.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                )");
        }
    }

    public function convertCommercialDocument(Request $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'target_document_kind' => 'required|string|in:INVOICE,RECEIPT,SALES_ORDER',
            'series' => 'nullable|string|max:10',
            'issue_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'cash_register_id' => 'nullable|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|in:ISSUED,DRAFT',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) $authUser->company_id;
        $sourceId = (int) $id;
        $roleContext = $this->resolveAuthRoleContext((int) $authUser->id, $companyId);
        $roleCode = strtoupper((string) ($roleContext['role_code'] ?? ''));
        $roleProfile = strtoupper((string) ($roleContext['role_profile'] ?? ''));

        if ($this->isCommerceFeatureEnabled($companyId, 'SALES_SELLER_TO_CASHIER') && !$this->isCashierActor($roleProfile, $roleCode)) {
            return response()->json([
                'message' => 'Solo caja puede convertir pedidos en este modo de venta.',
            ], 403);
        }

        $source = DB::table('sales.commercial_documents')
            ->where('id', $sourceId)
            ->where('company_id', $companyId)
            ->first();

        if (!$source) {
            return response()->json([
                'message' => 'Documento origen no encontrado',
            ], 404);
        }

        if (!in_array((string) $source->document_kind, ['QUOTATION', 'SALES_ORDER'], true)) {
            return response()->json([
                'message' => 'Solo se puede convertir cotizacion o pedido de venta',
            ], 422);
        }

        if (in_array((string) $source->status, ['VOID', 'CANCELED'], true)) {
            return response()->json([
                'message' => 'No se puede convertir un documento anulado/cancelado',
            ], 422);
        }

        $targetDocumentKind = (string) $payload['target_document_kind'];

        if ((string) $source->document_kind === 'SALES_ORDER' && $targetDocumentKind === 'SALES_ORDER') {
            return response()->json([
                'message' => 'El documento origen ya es una nota de pedido',
            ], 422);
        }

        $alreadyConverted = DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.document_kind', $targetDocumentKind)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceId])
            ->exists();

        if ($alreadyConverted) {
            return response()->json([
                'message' => 'El documento ya fue convertido a ' . $targetDocumentKind,
            ], 409);
        }

        $sourceItems = DB::table('sales.commercial_document_items')
            ->where('document_id', $sourceId)
            ->orderBy('line_no')
            ->get();

        if ($sourceItems->isEmpty()) {
            return response()->json([
                'message' => 'El documento origen no tiene items para convertir',
            ], 422);
        }

        $sourceItemIds = $sourceItems->pluck('id')->map(function ($rowId) {
            return (int) $rowId;
        })->values()->all();

        $lotsByItem = DB::table('sales.commercial_document_item_lots')
            ->whereIn('document_item_id', $sourceItemIds)
            ->get()
            ->groupBy('document_item_id');

        $series = isset($payload['series']) && trim((string) $payload['series']) !== ''
            ? trim((string) $payload['series'])
            : null;

        if ($series === null) {
            $candidateSeries = DB::table('sales.series_numbers')
                ->where('company_id', $companyId)
                ->where('document_kind', $targetDocumentKind)
                ->where('is_enabled', true)
                ->when($source->branch_id !== null, function ($query) use ($source) {
                    $query->where(function ($nested) use ($source) {
                        $nested->where('branch_id', (int) $source->branch_id)
                            ->orWhereNull('branch_id');
                    });
                })
                ->when($source->warehouse_id !== null, function ($query) use ($source) {
                    $query->where(function ($nested) use ($source) {
                        $nested->where('warehouse_id', (int) $source->warehouse_id)
                            ->orWhereNull('warehouse_id');
                    });
                })
                ->orderByDesc('branch_id')
                ->orderByDesc('warehouse_id')
                ->orderBy('series')
                ->first();

            if (!$candidateSeries) {
                return response()->json([
                    'message' => 'No existe serie habilitada para ' . $targetDocumentKind,
                ], 422);
            }

            $series = (string) $candidateSeries->series;
        }

        $sourceMetadata = [];
        if (isset($source->metadata) && $source->metadata !== null && $source->metadata !== '') {
            $decoded = json_decode((string) $source->metadata, true);
            if (is_array($decoded)) {
                $sourceMetadata = $decoded;
            }
        }

        $sourceHadStockImpact = $this->shouldAffectStock((string) $source->document_kind, (string) $source->status);

        $sourceNumber = (string) $source->series . '-' . (string) $source->number;
        $itemsPayload = $sourceItems->map(function ($item) use ($lotsByItem) {
            $itemLots = $lotsByItem->get((int) $item->id, collect())->map(function ($lot) {
                return [
                    'lot_id' => (int) $lot->lot_id,
                    'qty' => (float) $lot->qty,
                ];
            })->values()->all();

            return [
                'line_no' => (int) $item->line_no,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'unit_id' => $item->unit_id !== null ? (int) $item->unit_id : null,
                'price_tier_id' => $item->price_tier_id !== null ? (int) $item->price_tier_id : null,
                'tax_category_id' => $item->tax_category_id !== null ? (int) $item->tax_category_id : null,
                'description' => (string) $item->description,
                'qty' => (float) $item->qty,
                'qty_base' => (float) $item->qty_base,
                'conversion_factor' => (float) $item->conversion_factor,
                'base_unit_price' => (float) $item->base_unit_price,
                'unit_price' => (float) $item->unit_price,
                'unit_cost' => (float) $item->unit_cost,
                'wholesale_discount_percent' => (float) $item->wholesale_discount_percent,
                'price_source' => $item->price_source ?: 'MANUAL',
                'discount_total' => (float) $item->discount_total,
                'tax_total' => (float) $item->tax_total,
                'subtotal' => (float) $item->subtotal,
                'total' => (float) $item->total,
                'metadata' => null,
                'lots' => !empty($itemLots) ? $itemLots : null,
            ];
        })->values()->all();

        $forwardPayload = [
            'company_id' => $companyId,
            'branch_id' => $source->branch_id !== null ? (int) $source->branch_id : null,
            'warehouse_id' => $source->warehouse_id !== null ? (int) $source->warehouse_id : null,
            'cash_register_id' => isset($payload['cash_register_id'])
                ? (int) $payload['cash_register_id']
                : (isset($sourceMetadata['cash_register_id']) && $sourceMetadata['cash_register_id'] !== null
                    ? (int) $sourceMetadata['cash_register_id']
                    : null),
            'document_kind' => $targetDocumentKind,
            'series' => $series,
            'issue_at' => $payload['issue_at'] ?? now(),
            'due_at' => $payload['due_at'] ?? $source->due_at,
            'customer_id' => (int) $source->customer_id,
            'currency_id' => (int) $source->currency_id,
            'payment_method_id' => isset($payload['payment_method_id'])
                ? (int) $payload['payment_method_id']
                : ($source->payment_method_id !== null ? (int) $source->payment_method_id : null),
            'exchange_rate' => $source->exchange_rate !== null ? (float) $source->exchange_rate : null,
            'notes' => $payload['notes'] ?? $source->notes,
            'metadata' => array_merge($sourceMetadata, [
                'source_document_id' => $sourceId,
                'source_document_kind' => (string) $source->document_kind,
                'source_document_number' => $sourceNumber,
                'conversion_origin' => 'SALES_MODULE',
                'stock_already_discounted' => $sourceHadStockImpact,
            ]),
            'status' => $payload['status'] ?? 'ISSUED',
            'items' => $itemsPayload,
            'payments' => [],
        ];

        $forwardRequest = Request::create('/api/sales/commercial-documents', 'POST', $forwardPayload);
        $forwardRequest->attributes->set('auth_user', $authUser);

        return $this->createCommercialDocument($forwardRequest);
    }

    public function showCommercialDocument(Request $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $authUser->company_id;
        $documentId = (int) $id;

        $doc = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('core.currencies as cur', 'cur.id', '=', 'd.currency_id')
            ->leftJoin('core.payment_methods as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.due_at',
                'd.status',
                'd.total',
                'd.balance_due',
                'd.notes',
                'd.metadata',
                'cur.code as currency_code',
                'cur.symbol as currency_symbol',
                'pm.name as payment_method_name',
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                'c.doc_number as customer_doc_number',
                'c.address as customer_address',
            ])
            ->where('d.id', $documentId)
            ->where('d.company_id', $companyId)
            ->first();

        if (!$doc) {
            return response()->json([
                'message' => 'Documento no encontrado',
            ], 404);
        }

        $items = $this->resolveDocumentItemsWithFallback($companyId, $documentId);

        $allTaxCategories = $this->resolveTaxCategories($companyId);

        $mappedItems = $items->map(function ($item) use ($allTaxCategories) {
            $taxCat = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = is_array($taxCat) ? (string) ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV';
            $taxRate = is_array($taxCat) ? (float) ($taxCat['rate_percent'] ?? 0) : 0;

            return [
                'lineNo' => (int) $item->line_no,
                'qty' => (float) $item->qty,
                'unitLabel' => (string) ($item->unit_code ?? ''),
                'description' => (string) $item->description,
                'unitPrice' => (float) $item->unit_price,
                'lineTotal' => (float) $item->total,
                'taxCategoryId' => $item->tax_category_id,
                'taxLabel' => $taxLabel,
                'taxRate' => $taxRate,
                'taxAmount' => (float) $item->tax_total,
            ];
        })->values();

        $gravadaTotal = 0;
        $inafectaTotal = 0;
        $exoneradaTotal = 0;
        $taxTotal = 0;

        foreach ($items as $item) {
            $taxCat = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = is_array($taxCat) ? (string) ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV';

            if ($taxLabel === 'IGV') {
                $gravadaTotal += (float) ($item->subtotal ?? 0);
                $taxTotal += (float) ($item->tax_total ?? 0);
            } elseif ($taxLabel === 'Inafecta') {
                $inafectaTotal += (float) ($item->subtotal ?? 0);
            } elseif ($taxLabel === 'Exonerada') {
                $exoneradaTotal += (float) ($item->subtotal ?? 0);
            }
        }

        return response()->json([
            'data' => [
                'id' => (int) $doc->id,
                'documentKind' => (string) $doc->document_kind,
                'series' => (string) $doc->series,
                'number' => (int) $doc->number,
                'issueDate' => (string) ($doc->issue_at ?? ''),
                'dueDate' => $doc->due_at ? (string) $doc->due_at : null,
                'status' => (string) $doc->status,
                'currencyCode' => (string) ($doc->currency_code ?? 'PEN'),
                'currencySymbol' => (string) ($doc->currency_symbol ?? 'S/'),
                'paymentMethodName' => (string) ($doc->payment_method_name ?? '-'),
                'customerName' => (string) ($doc->customer_name ?? '-'),
                'customerDocNumber' => (string) ($doc->customer_doc_number ?? '-'),
                'customerAddress' => (string) ($doc->customer_address ?? '-'),
                'subtotal' => (float) ($gravadaTotal + $inafectaTotal + $exoneradaTotal),
                'taxTotal' => (float) $taxTotal,
                'grandTotal' => (float) $doc->total,
                'gravadaTotal' => (float) $gravadaTotal,
                'inafectaTotal' => (float) $inafectaTotal,
                'exoneradaTotal' => (float) $exoneradaTotal,
                'items' => $mappedItems,
            ],
        ]);
    }

    private function registerCashIncomeFromDocument(
        int $companyId,
        ?int $branchId,
        ?int $cashRegisterId,
        int $documentId,
        string $documentKind,
        string $series,
        int $number,
        float $paidTotal,
        int $userId,
        array $payments = []
    ): void {
        if ($cashRegisterId === null || $paidTotal <= 0) {
            return;
        }

        if (!$this->tableExists('sales.cash_sessions') || !$this->tableExists('sales.cash_movements')) {
            return;
        }

        $session = DB::table('sales.cash_sessions')
            ->where('company_id', $companyId)
            ->where('cash_register_id', $cashRegisterId)
            ->where('status', 'OPEN')
            ->orderByDesc('opened_at')
            ->first();

        if (!$session) {
            return;
        }

        $firstPaidMethod = collect($payments)
            ->first(function ($payment) {
                return ($payment['status'] ?? 'PENDING') === 'PAID';
            });

        DB::table('sales.cash_movements')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'cash_register_id' => $cashRegisterId,
            'cash_session_id' => (int) $session->id,
            'movement_type' => 'INCOME',
            'payment_method_id' => $firstPaidMethod['payment_method_id'] ?? null,
            'amount' => round($paidTotal, 4),
            'description' => 'Cobro doc ' . $documentKind . ' ' . $series . '-' . $number,
            'notes' => 'Cobro doc ' . $documentKind . ' ' . $series . '-' . $number,
            'ref_type' => 'COMMERCIAL_DOCUMENT',
            'ref_id' => $documentId,
            'created_by' => $userId,
            'user_id' => $userId,
            'movement_at' => now(),
            'created_at' => now(),
        ]);

        $totalIn = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        DB::table('sales.cash_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'expected_balance' => round((float) $session->opening_balance + $totalIn - $totalOut, 4),
            ]);
    }

    private function resolveTaxCategories(int $companyId)
    {
        $sourceTable = null;

        foreach (['core.tax_categories', 'sales.tax_categories'] as $candidate) {
            if ($this->tableExists($candidate)) {
                $sourceTable = $candidate;
                break;
            }
        }

        if (!$sourceTable) {
            return collect();
        }

        $columns = $this->tableColumns($sourceTable);
        $idColumn = $this->firstExistingColumn($columns, ['id']);
        $codeColumn = $this->firstExistingColumn($columns, ['code', 'sunat_code', 'tax_code']);
        $labelColumn = $this->firstExistingColumn($columns, ['name', 'label', 'description']);
        $rateColumn = $this->firstExistingColumn($columns, ['rate_percent', 'rate', 'percentage', 'tax_rate']);
        $statusColumn = $this->firstExistingColumn($columns, ['status', 'is_enabled', 'enabled', 'active']);
        $companyColumn = $this->firstExistingColumn($columns, ['company_id']);

        $query = DB::table($sourceTable);

        if ($statusColumn) {
            if ($statusColumn === 'status') {
                $query->where($statusColumn, 1);
            } else {
                $query->where($statusColumn, true);
            }
        }

        if ($companyColumn) {
            $query->where(function ($nested) use ($companyColumn, $companyId) {
                $nested->where($companyColumn, $companyId)
                    ->orWhereNull($companyColumn);
            });
        }

        $rows = $query->get()->map(function ($row) use ($idColumn, $codeColumn, $labelColumn, $rateColumn) {
            $id = $idColumn ? (int) ($row->{$idColumn} ?? 0) : 0;
            $code = $codeColumn ? (string) ($row->{$codeColumn} ?? '') : '';
            $label = $labelColumn ? (string) ($row->{$labelColumn} ?? '') : '';
            $rate = $rateColumn ? (float) ($row->{$rateColumn} ?? 0) : 0.0;

            if ($label === '') {
                $label = $code !== '' ? $code : ('IGV #' . $id);
            }

            return [
                'id' => $id,
                'code' => $code,
                'label' => $label,
                'rate_percent' => round($rate, 4),
            ];
        })->filter(function ($row) {
            return $row['id'] > 0;
        })->values();

        if ($rows->isEmpty()) {
            return collect();
        }

        return $rows;
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        return isset($row->present) && (bool) $row->present;
    }

    private function tableColumns(string $qualifiedTable): array
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        return collect(DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [$schema, $table]
        ))->map(function ($row) {
            return (string) $row->column_name;
        })->all();
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function enabledUnits(int $companyId)
    {
        $this->ensureCompanyUnitsTable();

        return DB::table('core.units as u')
            ->join('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select('u.id', 'u.code', 'u.sunat_uom_code', 'u.name')
            ->where('cu.is_enabled', true)
            ->orderBy('u.name')
            ->get();
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

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') === false) {
            return ['public', $qualifiedTable];
        }

        [$schema, $table] = explode('.', $qualifiedTable, 2);

        return [$schema, $table];
    }

    private function resolveDocumentItemsWithFallback(int $companyId, int $documentId, int $maxDepth = 5)
    {
        $visited = [];
        $currentDocumentId = $documentId;

        for ($depth = 0; $depth <= $maxDepth; $depth++) {
            if (in_array($currentDocumentId, $visited, true)) {
                break;
            }
            $visited[] = $currentDocumentId;

            $items = DB::table('sales.commercial_document_items as i')
                ->leftJoin('core.units as u', 'u.id', '=', 'i.unit_id')
                ->select([
                    'i.line_no',
                    'i.qty',
                    'i.description',
                    'i.unit_price',
                    'u.code as unit_code',
                    'i.tax_category_id',
                    'i.tax_total',
                    'i.subtotal',
                    'i.total',
                ])
                ->where('i.document_id', $currentDocumentId)
                ->orderBy('i.line_no')
                ->get();

            if (!$items->isEmpty()) {
                return $items;
            }

            $docRow = DB::table('sales.commercial_documents')
                ->select('id', 'metadata')
                ->where('company_id', $companyId)
                ->where('id', $currentDocumentId)
                ->first();

            if (!$docRow || $docRow->metadata === null || $docRow->metadata === '') {
                break;
            }

            $metadata = json_decode((string) $docRow->metadata, true);
            $nextDocumentId = is_array($metadata) ? (int) ($metadata['source_document_id'] ?? 0) : 0;

            if ($nextDocumentId <= 0) {
                break;
            }

            $currentDocumentId = $nextDocumentId;
        }

        return collect();
    }

    private function shouldAffectStock(string $documentKind, string $status): bool
    {
        if ($status !== 'ISSUED') {
            return false;
        }

        return in_array($documentKind, ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE', 'CREDIT_NOTE'], true);
    }

    private function stockDirectionForDocument(string $documentKind): int
    {
        if (in_array($documentKind, ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE'], true)) {
            return -1;
        }

        if ($documentKind === 'CREDIT_NOTE') {
            return 1;
        }

        return 0;
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

    private function isCommerceFeatureEnabled(int $companyId, string $featureCode): bool
    {
        return DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->where('is_enabled', true)
            ->exists();
    }

    private function isSellerActor(string $roleProfile, string $roleCode): bool
    {
        if ($roleProfile === 'SELLER') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        return strpos($roleCode, 'VENDED') !== false || strpos($roleCode, 'SELLER') !== false;
    }

    private function isCashierActor(string $roleProfile, string $roleCode): bool
    {
        if ($roleProfile === 'CASHIER') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        if (strpos($roleCode, 'ADMIN') !== false) {
            return true;
        }

        return strpos($roleCode, 'CAJA') !== false || strpos($roleCode, 'CAJER') !== false || strpos($roleCode, 'CASHIER') !== false;
    }

    private function resolveAuthRoleContext(int $userId, int $companyId): array
    {
        $this->ensureCompanyRoleProfilesTable();

        $row = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($companyId) {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', $companyId);
            })
            ->where('ur.user_id', $userId)
            ->where('r.company_id', $companyId)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code', 'crp.functional_profile as role_profile')
            ->first();

        return [
            'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
            'role_profile' => $row && $row->role_profile !== null ? (string) $row->role_profile : null,
        ];
    }

    private function ensureCompanyRoleProfilesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_role_profiles (
                company_id BIGINT NOT NULL,
                role_id BIGINT NOT NULL,
                functional_profile VARCHAR(20) NULL,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, role_id)
            )'
        );
    }

    private function resolveLineConversion(int $companyId, $product, array $item, ?int $itemUnitId): array
    {
        $qty = (float) ($item['qty'] ?? 0);

        if (!$product || !$product->unit_id) {
            $factor = isset($item['conversion_factor']) ? (float) $item['conversion_factor'] : 1.0;
            if ($factor <= 0) {
                $factor = 1.0;
            }

            $qtyBase = isset($item['qty_base']) ? (float) $item['qty_base'] : ($qty * $factor);
            if ($qtyBase <= 0) {
                $qtyBase = $qty;
            }

            $baseUnitPrice = isset($item['base_unit_price']) ? (float) $item['base_unit_price'] : ((float) $item['unit_price'] / max($factor, 0.00000001));

            return [
                'conversion_factor' => $factor,
                'qty_base' => $qtyBase,
                'base_unit_price' => $baseUnitPrice,
            ];
        }

        $baseUnitId = (int) $product->unit_id;
        $lineUnitId = $itemUnitId ?: $baseUnitId;

        $factor = null;
        if (isset($item['conversion_factor']) && (float) $item['conversion_factor'] > 0) {
            $factor = (float) $item['conversion_factor'];
        } else {
            $factor = $this->resolveConversionFactor($companyId, (int) $product->id, $lineUnitId, $baseUnitId);
        }

        if ($factor <= 0) {
            throw new \RuntimeException('Invalid conversion factor for product #' . $product->id);
        }

        $qtyBase = isset($item['qty_base']) && (float) $item['qty_base'] > 0
            ? (float) $item['qty_base']
            : ($qty * $factor);

        $baseUnitPrice = isset($item['base_unit_price']) && (float) $item['base_unit_price'] >= 0
            ? (float) $item['base_unit_price']
            : ((float) $item['unit_price'] / max($factor, 0.00000001));

        return [
            'conversion_factor' => $factor,
            'qty_base' => $qtyBase,
            'base_unit_price' => $baseUnitPrice,
        ];
    }

    private function resolveConversionFactor(int $companyId, int $productId, int $lineUnitId, int $baseUnitId): float
    {
        if ($lineUnitId === $baseUnitId) {
            return 1.0;
        }

        $direct = DB::table('inventory.product_uom_conversions')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('from_unit_id', $lineUnitId)
            ->where('to_unit_id', $baseUnitId)
            ->where('status', 1)
            ->value('conversion_factor');

        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }

        $inverse = DB::table('inventory.product_uom_conversions')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('from_unit_id', $baseUnitId)
            ->where('to_unit_id', $lineUnitId)
            ->where('status', 1)
            ->value('conversion_factor');

        if ($inverse !== null && (float) $inverse > 0) {
            return 1 / (float) $inverse;
        }

        throw new \RuntimeException('Missing conversion from unit ' . $lineUnitId . ' to base unit ' . $baseUnitId . ' for product #' . $productId);
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
}
