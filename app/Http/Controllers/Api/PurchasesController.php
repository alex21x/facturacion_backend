<?php

namespace App\Http\Controllers\Api;

use App\Application\Commands\Inventory\CreateInventoryStockEntryCommand;
use App\Application\Commands\Purchases\ExportPurchasesStockEntriesCommand;
use App\Application\Commands\Purchases\ListPurchasesStockEntriesCommand;
use App\Application\UseCases\Inventory\CreateInventoryStockEntryUseCase;
use App\Application\UseCases\Purchases\ExportPurchasesStockEntriesUseCase;
use App\Application\UseCases\Purchases\GetPurchasesLookupsUseCase;
use App\Application\UseCases\Purchases\ListPurchasesStockEntriesUseCase;
use App\Services\AppConfig\CommerceFeatureToggleService;
use App\Services\AppConfig\CompanyIgvRateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class PurchasesController
{
    private array $stockProjection = [];
    private array $lotStockProjection = [];

    public function __construct(
        private CommerceFeatureToggleService $featureToggles,
        private CompanyIgvRateService $companyIgvRateService,
        private GetPurchasesLookupsUseCase $getPurchasesLookupsUseCase,
        private ListPurchasesStockEntriesUseCase $listPurchasesStockEntriesUseCase,
        private ExportPurchasesStockEntriesUseCase $exportPurchasesStockEntriesUseCase,
        private CreateInventoryStockEntryUseCase $createInventoryStockEntryUseCase
    )
    {
    }

    /**
     * Receive a purchase order and convert it into an applied purchase entry.
     */
    public function receivePurchaseOrder(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'issue_at' => 'nullable|date',
            'reference_no' => 'nullable|string|max:60',
            'supplier_reference' => 'nullable|string|max:120',
            'payment_method_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:300',
            'items' => 'nullable|array|min:1',
            'items.*.product_id' => 'required_with:items|integer|min:1',
            'items.*.qty' => 'required_with:items|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $source = DB::table('inventory.stock_entries')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->where('entry_type', 'PURCHASE_ORDER')
            ->first();

        if (!$source) {
            return response()->json(['message' => 'Orden de compra no encontrada'], 404);
        }

        if (in_array((string) $source->status, ['CLOSED', 'VOID', 'CANCELED'], true)) {
            return response()->json(['message' => 'La orden de compra ya no puede recepcionarse'], 422);
        }

        $sourceItems = DB::table('inventory.stock_entry_items')
            ->where('entry_id', (int) $source->id)
            ->orderBy('id')
            ->get();

        if ($sourceItems->isEmpty()) {
            return response()->json(['message' => 'La orden de compra no tiene items para recepcion'], 422);
        }

        $orderedByProduct = [];
        foreach ($sourceItems as $item) {
            $productId = (int) $item->product_id;
            $orderedByProduct[$productId] = ($orderedByProduct[$productId] ?? 0.0) + (float) $item->qty;
        }

        $receivedByProduct = DB::table('inventory.stock_entries as se')
            ->join('inventory.stock_entry_items as sei', 'sei.entry_id', '=', 'se.id')
            ->where('se.company_id', $companyId)
            ->where('se.entry_type', 'PURCHASE')
            ->where('se.status', 'APPLIED')
            ->whereRaw("COALESCE((se.metadata->>'source_purchase_order_id')::BIGINT, 0) = ?", [(int) $source->id])
            ->groupBy('sei.product_id')
            ->selectRaw('sei.product_id, COALESCE(SUM(sei.qty), 0) as received_qty')
            ->get()
            ->reduce(function ($carry, $row) {
                $carry[(int) $row->product_id] = (float) $row->received_qty;
                return $carry;
            }, []);

        $remainingByProduct = [];
        foreach ($orderedByProduct as $productId => $orderedQty) {
            $alreadyReceived = (float) ($receivedByProduct[$productId] ?? 0.0);
            $remainingByProduct[$productId] = max($orderedQty - $alreadyReceived, 0.0);
        }

        $hasPending = false;
        foreach ($remainingByProduct as $remainingQty) {
            if ($remainingQty > 0.00000001) {
                $hasPending = true;
                break;
            }
        }

        if (!$hasPending) {
            return response()->json(['message' => 'La orden de compra no tiene saldo pendiente por recepcionar'], 422);
        }

        $requestedByProduct = [];
        if (!empty($payload['items']) && is_array($payload['items'])) {
            foreach ($orderedByProduct as $productId => $_) {
                $requestedByProduct[$productId] = 0.0;
            }

            foreach ($payload['items'] as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $qty = (float) ($line['qty'] ?? 0);

                if (!array_key_exists($productId, $orderedByProduct)) {
                    return response()->json(['message' => 'Producto no pertenece a la orden de compra'], 422);
                }

                if ($qty <= 0.00000001) {
                    return response()->json(['message' => 'Cantidad parcial invalida para recepcion'], 422);
                }

                $requestedByProduct[$productId] += $qty;
            }

            foreach ($requestedByProduct as $productId => $requestedQty) {
                $remainingQty = (float) ($remainingByProduct[$productId] ?? 0.0);
                if ($requestedQty - $remainingQty > 0.00000001) {
                    return response()->json(['message' => 'La cantidad parcial excede el saldo pendiente del producto #' . $productId], 422);
                }
            }
        } else {
            foreach ($remainingByProduct as $productId => $remainingQty) {
                $requestedByProduct[$productId] = (float) $remainingQty;
            }
        }

        $itemsForReception = [];
        $receivedInThisActionByProduct = [];
        foreach ($sourceItems as $item) {
            $productId = (int) $item->product_id;
            $requestedQty = (float) ($requestedByProduct[$productId] ?? 0.0);

            if ($requestedQty <= 0.00000001) {
                continue;
            }

            $lineQty = min((float) $item->qty, $requestedQty);
            if ($lineQty <= 0.00000001) {
                continue;
            }

            $itemsForReception[] = [
                'product_id' => $productId,
                'qty' => $lineQty,
                'unit_cost' => (float) ($item->unit_cost ?? 0),
                'tax_category_id' => $item->tax_category_id !== null ? (int) $item->tax_category_id : null,
                'tax_rate' => $item->tax_rate !== null ? (float) $item->tax_rate : 0,
                'notes' => $item->notes,
            ];

            $requestedByProduct[$productId] = max($requestedQty - $lineQty, 0.0);
            $receivedInThisActionByProduct[$productId] = ($receivedInThisActionByProduct[$productId] ?? 0.0) + $lineQty;
        }

        if (count($itemsForReception) === 0) {
            return response()->json(['message' => 'No se encontraron cantidades validas para recepcion parcial'], 422);
        }

        $sourceMetadata = [];
        if (isset($source->metadata) && $source->metadata !== null) {
            if (is_string($source->metadata)) {
                $decoded = json_decode($source->metadata, true);
                if (is_array($decoded)) {
                    $sourceMetadata = $decoded;
                }
            } elseif (is_array($source->metadata)) {
                $sourceMetadata = $source->metadata;
            }
        }

        $receivePayload = [
            'warehouse_id' => (int) $source->warehouse_id,
            'entry_type' => 'PURCHASE',
            'reference_no' => trim((string) ($payload['reference_no'] ?? ($source->reference_no ?? ''))),
            'supplier_reference' => trim((string) ($payload['supplier_reference'] ?? ($source->supplier_reference ?? ''))),
            'payment_method_id' => array_key_exists('payment_method_id', $payload)
                ? ($payload['payment_method_id'] !== null ? (int) $payload['payment_method_id'] : null)
                : ($source->payment_method_id !== null ? (int) $source->payment_method_id : null),
            'issue_at' => $payload['issue_at'] ?? now(),
            'notes' => trim((string) ($payload['notes'] ?? 'Recepcion OC #' . (int) $source->id)),
            'metadata' => array_merge($sourceMetadata, [
                'source_purchase_order_id' => (int) $source->id,
                'source_entry_type' => 'PURCHASE_ORDER',
                'reception_origin' => 'PURCHASE_ORDER',
            ]),
            'items' => $itemsForReception,
        ];

        try {
            $created = $this->createInventoryStockEntryUseCase->execute(
                CreateInventoryStockEntryCommand::fromInput(
                    $authUser,
                    $receivePayload,
                    $companyId,
                    $source->branch_id,
                    (int) $source->warehouse_id
                )
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $remainingAfter = [];
        foreach ($orderedByProduct as $productId => $orderedQty) {
            $alreadyReceived = (float) ($receivedByProduct[$productId] ?? 0.0);
            $justReceived = (float) ($receivedInThisActionByProduct[$productId] ?? 0.0);
            $remainingAfter[$productId] = max($orderedQty - ($alreadyReceived + $justReceived), 0.0);
        }

        $nextStatus = 'CLOSED';
        foreach ($remainingAfter as $remainingQty) {
            if ($remainingQty > 0.00000001) {
                $nextStatus = 'PARTIAL';
                break;
            }
        }

        $receivedEntryIds = [];
        if (isset($sourceMetadata['received_entry_ids']) && is_array($sourceMetadata['received_entry_ids'])) {
            $receivedEntryIds = array_values(array_map(fn ($entryId) => (int) $entryId, $sourceMetadata['received_entry_ids']));
        }

        $newReceivedEntryId = (int) ($created['id'] ?? 0);
        if ($newReceivedEntryId > 0) {
            $receivedEntryIds[] = $newReceivedEntryId;
        }

        $updatedMetadata = array_merge($sourceMetadata, [
            'received_entry_id' => $newReceivedEntryId,
            'received_entry_ids' => array_values(array_unique($receivedEntryIds)),
            'received_at' => now()->toIso8601String(),
            'remaining_by_product' => $remainingAfter,
        ]);

        DB::table('inventory.stock_entries')
            ->where('id', (int) $source->id)
            ->where('company_id', $companyId)
            ->update([
                'status' => $nextStatus,
                'metadata' => json_encode($updatedMetadata),
                'updated_by' => $authUser->id,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Orden de compra recepcionada correctamente',
            'data' => [
                'purchase_order_id' => (int) $source->id,
                'received_entry_id' => (int) ($created['id'] ?? 0),
                'status' => $nextStatus,
            ],
        ]);
    }

    /**
     * Edit a stock entry while preserving inventory traceability.
     */
    public function updateStockEntry(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'reference_no' => 'nullable|string|max:60',
            'supplier_reference' => 'nullable|string|max:120',
            'payment_method_id' => 'nullable|integer|min:1',
            'issue_at' => 'nullable|date',
            'notes' => 'nullable|string|max:300',
            'metadata' => 'nullable|array',
            'edit_reason' => 'nullable|string|max:180',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty' => 'required|numeric',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.lot_id' => 'nullable|integer|min:1',
            'items.*.lot_code' => 'nullable|string|max:80',
            'items.*.manufacture_at' => 'nullable|date',
            'items.*.expires_at' => 'nullable|date',
            'items.*.notes' => 'nullable|string|max:200',
            'items.*.metadata' => 'nullable|array',
            'items.*.metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $entry = DB::table('inventory.stock_entries')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$entry) {
            return response()->json(['message' => 'Ingreso no encontrado'], 404);
        }

        $entryType = strtoupper((string) $entry->entry_type);
        if (!in_array($entryType, ['PURCHASE', 'ADJUSTMENT', 'PURCHASE_ORDER'], true)) {
            return response()->json(['message' => 'Tipo de ingreso no editable'], 422);
        }

        if (in_array(strtoupper((string) $entry->status), ['VOID', 'CANCELED'], true)) {
            return response()->json(['message' => 'El ingreso ya no puede editarse'], 422);
        }

        $sourceMetadata = $this->decodeMetadata($entry->metadata ?? null);
        if ($entryType === 'PURCHASE_ORDER') {
            $receivedIds = $sourceMetadata['received_entry_ids'] ?? [];
            if (!is_array($receivedIds)) {
                $receivedIds = [];
            }

            if (count($receivedIds) > 0) {
                return response()->json([
                    'message' => 'La orden de compra ya tiene recepciones y no puede editarse para proteger la trazabilidad.'
                ], 422);
            }
        }

        $resolvedIssueAt = $this->resolveIssueAtForStorage($payload['issue_at'] ?? $entry->issue_at);
        $editOccurredAt = now('America/Lima')->format('Y-m-d H:i:sP');
        $inventorySettings = $this->inventorySettingsForCompany($companyId);
        $appliesStock = in_array($entryType, ['PURCHASE', 'ADJUSTMENT'], true);

        $productIds = collect($payload['items'])
            ->pluck('product_id')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        $products = DB::table('inventory.products')
            ->select('id', 'status', 'is_stockable', 'lot_tracking')
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds->all())
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            return response()->json(['message' => 'Uno o mas productos no existen o no estan disponibles.'], 422);
        }

        $stockEntryItemColumns = $this->tableColumns('inventory.stock_entry_items');
        $inventoryLedgerColumns = $this->tableColumns('inventory.inventory_ledger');
        $stockEntryColumns = $this->tableColumns('inventory.stock_entries');

        $hasItemTaxCategoryColumn = in_array('tax_category_id', $stockEntryItemColumns, true);
        $hasItemTaxRateColumn = in_array('tax_rate', $stockEntryItemColumns, true);
        $hasLedgerTaxRateColumn = in_array('tax_rate', $inventoryLedgerColumns, true);
        $hasPaymentMethodColumn = in_array('payment_method_id', $stockEntryColumns, true);
        $hasMetadataColumn = in_array('metadata', $stockEntryColumns, true);

        try {
            DB::transaction(function () use (
                $entry,
                $entryType,
                $payload,
                $resolvedIssueAt,
                $editOccurredAt,
                $authUser,
                $companyId,
                $sourceMetadata,
                $inventorySettings,
                $appliesStock,
                $products,
                $hasItemTaxCategoryColumn,
                $hasItemTaxRateColumn,
                $hasLedgerTaxRateColumn,
                $hasPaymentMethodColumn,
                $hasMetadataColumn
            ) {
                if ($appliesStock) {
                    $this->clearPreviousEditLedgerForEntry($companyId, (int) $entry->id);

                    $this->reverseStockLedgerForEntryEdit(
                        $companyId,
                        (int) $entry->id,
                        (int) $authUser->id,
                        $editOccurredAt,
                        $inventorySettings,
                        $hasLedgerTaxRateColumn
                    );
                }

                DB::table('inventory.stock_entry_items')
                    ->where('entry_id', (int) $entry->id)
                    ->delete();

                foreach ($payload['items'] as $index => $item) {
                    $productId = (int) $item['product_id'];
                    $product = $products->get($productId);
                    $qty = round((float) $item['qty'], 8);
                    $unitCost = isset($item['unit_cost']) ? (float) $item['unit_cost'] : 0.0;
                    $taxCategoryId = isset($item['tax_category_id']) ? (int) $item['tax_category_id'] : null;
                    $taxRate = isset($item['tax_rate']) ? round((float) $item['tax_rate'], 2) : 0.0;

                    if (!$product || (int) $product->status !== 1 || !(bool) $product->is_stockable) {
                        throw new \RuntimeException('Producto invalido para la linea ' . ($index + 1));
                    }

                    if ($entryType === 'PURCHASE' && $qty <= 0) {
                        throw new \RuntimeException('Cantidad invalida para la linea ' . ($index + 1));
                    }

                    if ($entryType === 'PURCHASE_ORDER' && $qty <= 0) {
                        throw new \RuntimeException('Cantidad invalida para la linea ' . ($index + 1));
                    }

                    if ($entryType === 'ADJUSTMENT' && abs($qty) < 0.00000001) {
                        throw new \RuntimeException('Cantidad invalida para la linea ' . ($index + 1));
                    }

                    $lotId = isset($item['lot_id']) ? (int) $item['lot_id'] : null;
                    $lotCode = isset($item['lot_code']) ? trim((string) $item['lot_code']) : '';
                    $lotTrackingEnabled = (bool) ($inventorySettings['enable_inventory_pro'] ?? false)
                        && (bool) ($inventorySettings['enable_lot_tracking'] ?? false);

                    if ($appliesStock && $lotTrackingEnabled && (bool) $product->lot_tracking && !$lotId && $lotCode !== '') {
                        $lotId = (int) DB::table('inventory.product_lots')->insertGetId([
                            'company_id' => $companyId,
                            'warehouse_id' => (int) $entry->warehouse_id,
                            'product_id' => $productId,
                            'lot_code' => $lotCode,
                            'manufacture_at' => $item['manufacture_at'] ?? null,
                            'expires_at' => $item['expires_at'] ?? null,
                            'received_at' => $editOccurredAt,
                            'status' => 1,
                            'created_by' => $authUser->id,
                            'created_at' => now(),
                        ]);
                    }

                    if ($appliesStock && $lotId) {
                        $lotExists = DB::table('inventory.product_lots')
                            ->where('id', $lotId)
                            ->where('company_id', $companyId)
                            ->where('warehouse_id', (int) $entry->warehouse_id)
                            ->where('product_id', $productId)
                            ->where('status', 1)
                            ->exists();

                        if (!$lotExists) {
                            throw new \RuntimeException('Lote invalido para la linea ' . ($index + 1));
                        }
                    }

                    $entryItemInsert = [
                        'entry_id' => (int) $entry->id,
                        'product_id' => $productId,
                        'lot_id' => $lotId,
                        'qty' => $qty,
                        'unit_cost' => $unitCost,
                        'notes' => $item['notes'] ?? null,
                        'created_at' => now(),
                    ];

                    if ($hasItemTaxCategoryColumn) {
                        $entryItemInsert['tax_category_id'] = $taxCategoryId;
                    }

                    if ($hasItemTaxRateColumn) {
                        $entryItemInsert['tax_rate'] = $taxRate;
                    }

                    DB::table('inventory.stock_entry_items')->insert($entryItemInsert);

                    if ($appliesStock) {
                        $this->applyStockForEditedEntryLine(
                            $companyId,
                            (int) $entry->warehouse_id,
                            (int) $entry->id,
                            $entryType,
                            $productId,
                            $lotId,
                            $qty,
                            $unitCost,
                            $taxRate,
                            $editOccurredAt,
                            (int) $authUser->id,
                            (bool) ($inventorySettings['allow_negative_stock'] ?? false),
                            $hasLedgerTaxRateColumn
                        );

                        if ($entryType === 'PURCHASE' && $unitCost > 0) {
                            DB::table('inventory.products')
                                ->where('id', $productId)
                                ->where('company_id', $companyId)
                                ->update([
                                    'cost_price' => $unitCost,
                                ]);
                        }
                    }
                }

                $nextMetadata = $sourceMetadata;
                if ($hasMetadataColumn && array_key_exists('metadata', $payload) && is_array($payload['metadata'])) {
                    $nextMetadata = $payload['metadata'];
                }

                $trail = [];
                if (isset($nextMetadata['edit_trail']) && is_array($nextMetadata['edit_trail'])) {
                    $trail = $nextMetadata['edit_trail'];
                }

                $trail[] = [
                    'edited_by' => (int) $authUser->id,
                    'edited_at' => now()->toIso8601String(),
                    'reason' => trim((string) ($payload['edit_reason'] ?? 'Edicion de compra')),
                ];

                $nextMetadata['edit_trail'] = array_slice($trail, -20);
                $nextMetadata['last_edited_by'] = (int) $authUser->id;
                $nextMetadata['last_edited_at'] = now()->toIso8601String();

                $entryUpdate = [
                    'reference_no' => array_key_exists('reference_no', $payload)
                        ? (trim((string) ($payload['reference_no'] ?? '')) !== '' ? trim((string) $payload['reference_no']) : null)
                        : $entry->reference_no,
                    'supplier_reference' => array_key_exists('supplier_reference', $payload)
                        ? (trim((string) ($payload['supplier_reference'] ?? '')) !== '' ? trim((string) $payload['supplier_reference']) : null)
                        : $entry->supplier_reference,
                    'issue_at' => $resolvedIssueAt,
                    'notes' => array_key_exists('notes', $payload)
                        ? (trim((string) ($payload['notes'] ?? '')) !== '' ? trim((string) $payload['notes']) : null)
                        : $entry->notes,
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ];

                if ($hasPaymentMethodColumn && array_key_exists('payment_method_id', $payload)) {
                    $entryUpdate['payment_method_id'] = $payload['payment_method_id'] !== null
                        ? (int) $payload['payment_method_id']
                        : null;
                }

                if ($hasMetadataColumn) {
                    $entryUpdate['metadata'] = json_encode($nextMetadata);
                }

                DB::table('inventory.stock_entries')
                    ->where('id', (int) $entry->id)
                    ->where('company_id', $companyId)
                    ->update($entryUpdate);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Ingreso actualizado correctamente',
            'data' => [
                'id' => (int) $entry->id,
            ],
        ]);
    }

    /**
     * Get lookups for purchases (payment methods, etc.)
     */
    public function lookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        $baseLookups = $this->getPurchasesLookupsUseCase->execute($companyId);

        $detraccionEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_DETRACCION_ENABLED');
        $retencionCompradorEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_RETENCION_COMPRADOR_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_RETENCION_COMPRADOR_ENABLED');
        $retencionProveedorEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_RETENCION_PROVEEDOR_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_RETENCION_PROVEEDOR_ENABLED');
        $percepcionEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_PERCEPCION_ENABLED');
        $globalDiscountEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_GLOBAL_DISCOUNT_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_GLOBAL_DISCOUNT_ENABLED');
        $itemDiscountEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_ITEM_DISCOUNT_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_ITEM_DISCOUNT_ENABLED');
        $freeOperationEnabled = $this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_FREE_ITEMS_ENABLED')
            || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_FREE_ITEMS_ENABLED');

        $retencionFeatureCode = $retencionCompradorEnabled
            ? 'PURCHASES_RETENCION_COMPRADOR_ENABLED'
            : ($retencionProveedorEnabled ? 'PURCHASES_RETENCION_PROVEEDOR_ENABLED' : null);

        return response()->json([
            'payment_methods' => $baseLookups['payment_methods'],
            'tax_categories' => $baseLookups['tax_categories'],
            'active_igv_rate_percent' => $this->companyIgvRateService->resolveActiveRatePercent($companyId),
            'inventory_settings' => $baseLookups['inventory_settings'],
            'detraccion_service_codes' => $detraccionEnabled ? $this->resolveDetractionServiceCodes() : [],
            'detraccion_min_amount' => $detraccionEnabled ? $this->getDetractionMinAmount($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED') : null,
            'detraccion_account' => $detraccionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED', 'DETRACCION') : null,
            'retencion_comprador_enabled' => $retencionCompradorEnabled,
            'retencion_proveedor_enabled' => $retencionProveedorEnabled,
            'retencion_types' => ($retencionCompradorEnabled || $retencionProveedorEnabled)
                ? $this->resolveRetencionTypes($companyId, $branchId, $retencionCompradorEnabled, $retencionProveedorEnabled)
                : [],
            'retencion_account' => $retencionFeatureCode
                ? $this->resolveFeatureAccountInfo($companyId, $branchId, $retencionFeatureCode, 'RETENCION')
                : null,
            'retencion_percentage' => 3.00,
            'percepcion_enabled' => $percepcionEnabled,
            'global_discount_enabled' => $globalDiscountEnabled,
            'item_discount_enabled' => $itemDiscountEnabled,
            'free_operation_enabled' => $freeOperationEnabled,
            'percepcion_types' => $percepcionEnabled ? $this->resolvePercepcionTypes($companyId, $branchId) : [],
            'percepcion_account' => $percepcionEnabled
                ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED', 'PERCEPCION')
                : null,
            'sunat_operation_types' => ($detraccionEnabled || $retencionCompradorEnabled || $retencionProveedorEnabled || $percepcionEnabled)
                ? $this->resolveSunatOperationTypes($companyId, $branchId)
                : [],
        ]);
    }

    /**
     * List stock entries (purchases and adjustments) with filtering and pagination
     */
    public function listStockEntries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id');

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        // Filtering parameters
        $entryType = $request->query('entry_type'); // PURCHASE, ADJUSTMENT, or null for both
        $reference = $request->query('reference');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $warehouseId = $request->query('warehouse_id');

        // Pagination
        $perPage = min((int) $request->query('per_page', 10), 100);
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $result = $this->listPurchasesStockEntriesUseCase->execute(
            ListPurchasesStockEntriesCommand::fromInput(
                $companyId,
                $branchId,
                $entryType,
                $reference,
                $dateFrom,
                $dateTo,
                $warehouseId,
                $page,
                $perPage
            )
        );

        return response()->json([
            'data' => $result['data'],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * Export stock entries as CSV
     */
    public function exportStockEntries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id');

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        // Filtering parameters (same as listStockEntries)
        $entryType = $request->query('entry_type');
        $reference = $request->query('reference');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $warehouseId = $request->query('warehouse_id');
        $format = strtolower($request->query('format', 'csv')); // csv or xlsx

        $entries = $this->exportPurchasesStockEntriesUseCase->execute(
            ExportPurchasesStockEntriesCommand::fromInput(
                $companyId,
                $branchId,
                $entryType,
                $reference,
                $dateFrom,
                $dateTo,
                $warehouseId,
                $format === 'json'
            )
        );

        if ($format === 'json') {
            return response()->json([
                'data' => $entries,
            ]);
        }

        if ($format === 'xlsx') {
            return $this->exportAsExcel($entries);
        } else {
            return $this->exportAsCsv($entries);
        }
    }

    /**
     * Attach detail lines for each stock entry.
     */
    private function attachEntryItems($entries, int $companyId)
    {
        $entryIds = collect($entries)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->filter(function ($id) {
            return $id > 0;
        })->values();

        if ($entryIds->isEmpty()) {
            return $entries;
        }

        $itemColumns = $this->tableColumns('inventory.stock_entry_items');
        $hasTaxCategory = in_array('tax_category_id', $itemColumns, true);
        $hasTaxRate = in_array('tax_rate', $itemColumns, true);
        $hasItemMetadata = in_array('metadata', $itemColumns, true);

        $taxById = $this->resolveTaxCategories($companyId)->keyBy('id');

        $items = DB::table('inventory.stock_entry_items as sei')
            ->leftJoin('inventory.products as p', 'sei.product_id', '=', 'p.id')
            ->leftJoin('inventory.product_lots as pl', 'sei.lot_id', '=', 'pl.id')
            ->whereIn('sei.entry_id', $entryIds->all())
            ->select([
                'sei.entry_id',
                'sei.product_id',
                DB::raw('COALESCE(p.name, CONCAT(\'Producto #\', sei.product_id)) as product_name'),
                'sei.qty',
                'sei.unit_cost',
                $hasTaxCategory ? 'sei.tax_category_id' : DB::raw('NULL as tax_category_id'),
                $hasTaxRate ? 'sei.tax_rate' : DB::raw('0 as tax_rate'),
                $hasItemMetadata ? 'sei.metadata' : DB::raw('NULL as metadata'),
                'sei.notes',
                'pl.lot_code',
            ])
            ->orderBy('sei.entry_id')
            ->orderBy('p.name')
            ->orderBy('sei.id')
            ->get()
            ->map(function ($row) use ($taxById) {
                $itemMetadata = $this->decodeMetadata($row->metadata ?? null);
                $subtotal = (float) $row->qty * (float) $row->unit_cost;
                $taxRate = (float) ($row->tax_rate ?? 0);
                $taxAmount = $subtotal * ($taxRate / 100);
                $lineDiscount = isset($itemMetadata['discount_total']) ? (float) $itemMetadata['discount_total'] : 0.0;
                $safeDiscount = max(0.0, min($lineDiscount, $subtotal + $taxAmount));
                $isFreeOperation = !empty($itemMetadata['is_free_operation']);
                if ($isFreeOperation) {
                    $safeDiscount = $subtotal + $taxAmount;
                    $itemMetadata['gratuitas'] = round($subtotal, 4);
                }
                $taxCategoryId = $row->tax_category_id ? (int) $row->tax_category_id : null;
                $taxRow = $taxCategoryId ? $taxById->get($taxCategoryId) : null;

                return [
                    'entry_id' => (int) $row->entry_id,
                    'product_id' => (int) $row->product_id,
                    'product_name' => (string) $row->product_name,
                    'qty' => (float) $row->qty,
                    'unit_cost' => (float) $row->unit_cost,
                    'subtotal' => round($subtotal, 4),
                    'tax_category_id' => $taxCategoryId,
                    'tax_label' => $taxRow['label'] ?? 'Sin IGV',
                    'tax_rate' => $taxRate,
                    'tax_amount' => round($taxAmount, 4),
                    'discount_total' => round($safeDiscount, 4),
                    'line_total' => round(max(($subtotal + $taxAmount) - $safeDiscount, 0), 4),
                    'lot_code' => $row->lot_code,
                    'notes' => $row->notes,
                    'metadata' => $itemMetadata,
                ];
            })
            ->groupBy('entry_id');

        return collect($entries)->map(function ($entry) use ($items) {
            $row = (array) $entry;
            $row['items'] = $items->get((int) $entry->id, collect())->values()->all();
            return $row;
        })->values()->all();
    }

    /**
     * Export entries as CSV
     */
    private function exportAsCsv($entries)
    {
        $csv = "ID,Tipo,Referencia,Referencia_Proveedor,Fecha,Almacen,Cantidad_Items,Cantidad_Total,Descuento_Item,Descuento_Global,Descuento_Total,Importe_Total,Metodo_Pago,Notas\n";

        foreach ($entries as $entry) {
            $metadata = [];
            if (isset($entry->metadata) && $entry->metadata !== null && $entry->metadata !== '') {
                $decoded = is_string($entry->metadata) ? json_decode($entry->metadata, true) : $entry->metadata;
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            $itemDiscount = (float) ($metadata['item_discount_total'] ?? 0);
            $globalDiscount = (float) ($metadata['discount_total'] ?? 0);
            $discountTotal = $itemDiscount + $globalDiscount;

            $row = [
                $entry->id,
                $entry->entry_type === 'PURCHASE'
                    ? 'Compra'
                    : ($entry->entry_type === 'PURCHASE_ORDER' ? 'Orden de compra' : 'Ajuste'),
                '"' . str_replace('"', '""', $entry->reference_no ?? '') . '"',
                '"' . str_replace('"', '""', $entry->supplier_reference ?? '') . '"',
                substr($entry->issue_at, 0, 10),
                '"' . str_replace('"', '""', $entry->warehouse_name ?? $entry->warehouse_code ?? '') . '"',
                $entry->total_items,
                number_format($entry->total_qty, 3, '.', ''),
                number_format($itemDiscount, 2, '.', ''),
                number_format($globalDiscount, 2, '.', ''),
                number_format($discountTotal, 2, '.', ''),
                number_format($entry->total_amount, 2, '.', ''),
                '"' . str_replace('"', '""', $entry->payment_method ?? '') . '"',
                '"' . str_replace('"', '""', $entry->notes ?? '') . '"',
            ];
            $csv .= implode(',', $row) . "\n";
        }

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="reporte_compras_' . date('Ymd_His') . '.csv"');
    }

    /**
     * Export entries as Excel (basic: using CSV format for now, can integrate PhpSpreadsheet later)
     */
    private function exportAsExcel($entries)
    {
        // For now, return the same CSV but signal it as Excel
        // Full Excel support requires package: composer require maatwebsite/excel
        return $this->exportAsCsv($entries)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="reporte_compras_' . date('Ymd_His') . '.xlsx"');
    }

    private function resolveTaxCategories(int $companyId)
    {
        $sourceTable = null;

        foreach (['core.tax_categories', 'sales.tax_categories', 'appcfg.tax_categories'] as $candidate) {
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

        return $query->get()->map(function ($row) use ($idColumn, $codeColumn, $labelColumn, $rateColumn) {
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
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return [
                'complexity_mode' => 'BASIC',
                'inventory_mode' => 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => 'MANUAL',
                'enable_inventory_pro' => false,
                'enable_lot_tracking' => false,
                'enable_expiry_tracking' => false,
                'enable_advanced_reporting' => false,
                'enable_graphical_dashboard' => false,
                'enable_location_control' => false,
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => false,
            ];
        }

        return [
            'complexity_mode' => (string) ($row->complexity_mode ?? 'BASIC'),
            'inventory_mode' => (string) ($row->inventory_mode ?? 'KARDEX_SIMPLE'),
            'lot_outflow_strategy' => (string) ($row->lot_outflow_strategy ?? 'MANUAL'),
            'enable_inventory_pro' => (bool) ($row->enable_inventory_pro ?? false),
            'enable_lot_tracking' => (bool) ($row->enable_lot_tracking ?? false),
            'enable_expiry_tracking' => (bool) ($row->enable_expiry_tracking ?? false),
            'enable_advanced_reporting' => (bool) ($row->enable_advanced_reporting ?? false),
            'enable_graphical_dashboard' => (bool) ($row->enable_graphical_dashboard ?? false),
            'enable_location_control' => (bool) ($row->enable_location_control ?? false),
            'allow_negative_stock' => (bool) ($row->allow_negative_stock ?? false),
            'enforce_lot_for_tracked' => (bool) ($row->enforce_lot_for_tracked ?? false),
        ];
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

    private function tableColumns(string $qualifiedTable)
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $rows = DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [$schema, $table]
        );

        return collect($rows)->map(function ($row) {
            return (string) $row->column_name;
        })->values()->all();
    }

    private function firstExistingColumn(array $columns, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') !== false) {
            return explode('.', $qualifiedTable, 2);
        }

        return ['public', $qualifiedTable];
    }

    private function resolveIssueAtForStorage($issueAt): string
    {
        if ($issueAt === null || $issueAt === '') {
            return now('America/Lima')->format('Y-m-d H:i:sP');
        }

        $text = trim((string) $issueAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $limaNow = now('America/Lima');
            return $text . ' ' . $limaNow->format('H:i:sP');
        }

        try {
            return Carbon::parse($text)->setTimezone('America/Lima')->format('Y-m-d H:i:sP');
        } catch (\Throwable $e) {
            return now('America/Lima')->format('Y-m-d H:i:sP');
        }
    }

    private function decodeMetadata($metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($metadata)) {
            $decoded = json_decode(json_encode($metadata), true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function reverseStockLedgerForEntryEdit(
        int $companyId,
        int $entryId,
        int $userId,
        string $movedAt,
        array $settings,
        bool $hasLedgerTaxRateColumn
    ): void {
        $rows = DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'STOCK_ENTRY')
            ->where('ref_id', $entryId)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $originalType = strtoupper((string) $row->movement_type);
            if (!in_array($originalType, ['IN', 'OUT'], true)) {
                continue;
            }

            $reverseType = $originalType === 'IN' ? 'OUT' : 'IN';
            $qty = round((float) ($row->quantity ?? 0), 8);
            if ($qty <= 0) {
                continue;
            }

            $delta = $reverseType === 'IN' ? $qty : -$qty;

            $this->applyCurrentStockDelta(
                $companyId,
                (int) $row->warehouse_id,
                (int) $row->product_id,
                $delta,
                (bool) ($settings['allow_negative_stock'] ?? false)
            );

            if ($row->lot_id !== null) {
                $this->applyLotStockDelta(
                    $companyId,
                    (int) $row->warehouse_id,
                    (int) $row->product_id,
                    (int) $row->lot_id,
                    $delta,
                    (bool) ($settings['allow_negative_stock'] ?? false)
                );
            }

            $insert = [
                'company_id' => $companyId,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
                'lot_id' => $row->lot_id !== null ? (int) $row->lot_id : null,
                'movement_type' => $reverseType,
                'quantity' => $qty,
                'unit_cost' => (float) ($row->unit_cost ?? 0),
                'ref_type' => 'STOCK_ENTRY_EDIT',
                'ref_id' => $entryId,
                'notes' => 'Reversa por edicion de ingreso #' . $entryId,
                'moved_at' => $movedAt,
                'created_by' => $userId,
            ];

            if ($hasLedgerTaxRateColumn) {
                $insert['tax_rate'] = round((float) ($row->tax_rate ?? 0), 2);
            }

            DB::table('inventory.inventory_ledger')->insert($insert);
        }
    }

    private function clearPreviousEditLedgerForEntry(int $companyId, int $entryId): void
    {
        DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'STOCK_ENTRY_EDIT')
            ->where('ref_id', $entryId)
            ->delete();
    }

    private function applyStockForEditedEntryLine(
        int $companyId,
        int $warehouseId,
        int $entryId,
        string $entryType,
        int $productId,
        ?int $lotId,
        float $qty,
        float $unitCost,
        float $taxRate,
        string $movedAt,
        int $userId,
        bool $allowNegativeStock,
        bool $hasLedgerTaxRateColumn
    ): void {
        $movementType = $entryType === 'PURCHASE' ? 'IN' : ($qty >= 0 ? 'IN' : 'OUT');
        $delta = $qty;

        $this->applyCurrentStockDelta($companyId, $warehouseId, $productId, $delta, $allowNegativeStock);

        if ($lotId !== null) {
            $this->applyLotStockDelta($companyId, $warehouseId, $productId, $lotId, $delta, $allowNegativeStock);
        }

        $insert = [
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'lot_id' => $lotId,
            'movement_type' => $movementType,
            'quantity' => round(abs($qty), 8),
            'unit_cost' => $unitCost,
            'ref_type' => 'STOCK_ENTRY_EDIT',
            'ref_id' => $entryId,
            'notes' => 'Reaplicacion por edicion de ingreso #' . $entryId,
            'moved_at' => $movedAt,
            'created_by' => $userId,
        ];

        if ($hasLedgerTaxRateColumn) {
            $insert['tax_rate'] = round($taxRate, 2);
        }

        DB::table('inventory.inventory_ledger')->insert($insert);
    }

    private function applyCurrentStockDelta(int $companyId, int $warehouseId, int $productId, float $delta, bool $allowNegativeStock): void
    {
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
            throw new \RuntimeException('Stock insuficiente para producto #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }

    private function applyLotStockDelta(int $companyId, int $warehouseId, int $productId, int $lotId, float $delta, bool $allowNegativeStock): void
    {
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
            throw new \RuntimeException('Stock insuficiente para lote #' . $lotId);
        }

        $this->lotStockProjection[$projectionKey] = round($next, 8);
    }

    private function isFeatureEnabled(int $companyId, $branchId, string $featureCode): bool
    {
        return $this->featureToggles->isFeatureEnabledForContext($companyId, $branchId, $featureCode);
    }

    private function isCommerceFeatureEnabled(int $companyId, string $featureCode): bool
    {
        return $this->featureToggles->isCompanyFeatureEnabled($companyId, $featureCode);
    }

    private function getDetractionMinAmount(int $companyId, $branchId, string $featureCode): float
    {
        $row = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        if ($row) {
            $config = $this->decodeFeatureConfig($row->config ?? null);
            if (isset($config['min_amount']) && is_numeric($config['min_amount'])) {
                return (float) $config['min_amount'];
            }
        }

        return 700.00;
    }

    private function resolveRetencionTypes(int $companyId, $branchId, bool $retencionCompradorEnabled, bool $retencionProveedorEnabled): array
    {
        $defaultRate = 3.00;
        $defaultType = [
            'code' => 'RET_IGV_3',
            'name' => 'Retencion IGV',
            'rate_percent' => $defaultRate,
        ];

        $configuredTypes = [];
        if ($retencionCompradorEnabled) {
            $row = $this->resolveFeatureToggleRow($companyId, $branchId, 'PURCHASES_RETENCION_COMPRADOR_ENABLED');
            $config = $this->decodeFeatureConfig($row ? $row->config : null);
            if (isset($config['retencion_types']) && is_array($config['retencion_types'])) {
                $configuredTypes = array_merge($configuredTypes, $config['retencion_types']);
            }
        }
        if ($retencionProveedorEnabled) {
            $row = $this->resolveFeatureToggleRow($companyId, $branchId, 'PURCHASES_RETENCION_PROVEEDOR_ENABLED');
            $config = $this->decodeFeatureConfig($row ? $row->config : null);
            if (isset($config['retencion_types']) && is_array($config['retencion_types'])) {
                $configuredTypes = array_merge($configuredTypes, $config['retencion_types']);
            }
        }

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePercepcionTypes(int $companyId, $branchId): array
    {
        $defaultRate = 2.00;
        $defaultType = [
            'code' => 'PERC_IGV_2',
            'name' => 'Percepcion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
        $configuredTypes = isset($config['percepcion_types']) && is_array($config['percepcion_types'])
            ? $config['percepcion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolveSunatOperationTypes(int $companyId, $branchId): array
    {
        $defaultRows = [
            ['code' => '0101', 'name' => 'Compra interna', 'regime' => 'NONE'],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION'],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION'],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION'],
        ];

        $featureCodes = [
            'PURCHASES_DETRACCION_ENABLED',
            'PURCHASES_RETENCION_COMPRADOR_ENABLED',
            'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
            'PURCHASES_PERCEPCION_ENABLED',
        ];

        $configuredRows = [];
        foreach ($featureCodes as $featureCode) {
            $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
            $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
            if (isset($config['sunat_operation_types']) && is_array($config['sunat_operation_types'])) {
                $configuredRows = array_merge($configuredRows, $config['sunat_operation_types']);
            }
        }

        $rows = collect($configuredRows)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $regime = strtoupper(trim((string) ($item['regime'] ?? 'NONE')));
                if (!in_array($regime, ['NONE', 'DETRACCION', 'RETENCION', 'PERCEPCION'], true)) {
                    $regime = 'NONE';
                }

                return [
                    'code' => $code,
                    'name' => $name,
                    'regime' => $regime,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : $defaultRows;
    }

    private function resolveFeatureAccountInfo(int $companyId, $branchId, string $featureCode, string $fallbackKeyword): ?array
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $accountNumber = trim((string) ($config['account_number'] ?? ''));
        if ($accountNumber !== '') {
            return [
                'bank_name' => trim((string) ($config['bank_name'] ?? '')),
                'account_number' => $accountNumber,
                'account_holder' => trim((string) ($config['account_holder'] ?? '')),
            ];
        }

        $bankAccounts = $this->resolveCompanyBankAccounts($companyId);
        $keyword = strtoupper(trim($fallbackKeyword));
        foreach ($bankAccounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountType = strtoupper(trim((string) ($account['account_type'] ?? '')));
            $number = trim((string) ($account['account_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            if ($keyword !== '' && strpos($accountType, $keyword) === false) {
                continue;
            }

            return [
                'bank_name' => trim((string) ($account['bank_name'] ?? '')),
                'account_number' => $number,
                'account_holder' => trim((string) ($account['account_holder'] ?? '')),
            ];
        }

        return null;
    }

    private function resolveCompanyBankAccounts(int $companyId): array
    {
        if (!$this->tableExists('core.company_settings')) {
            return [];
        }

        $row = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('bank_accounts')
            ->first();

        if (!$row || $row->bank_accounts === null) {
            return [];
        }

        $decoded = is_string($row->bank_accounts)
            ? json_decode($row->bank_accounts, true)
            : (array) $row->bank_accounts;

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function ($item) {
            return is_array($item);
        }));
    }

    private function resolveFeatureToggleRow(int $companyId, $branchId, string $featureCode)
    {
        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->first();

            if ($branchRow && (bool) ($branchRow->is_enabled ?? false)) {
                return $branchRow;
            }

            if ($companyRow && (bool) ($companyRow->is_enabled ?? false)) {
                return $companyRow;
            }

            if ($branchRow) {
                return $branchRow;
            }
        }

        return $companyRow;
    }

    private function decodeFeatureConfig($rawConfig): array
    {
        if ($rawConfig === null) {
            return [];
        }

        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        return [];
    }

    private function resolveDetractionServiceCodes(): array
    {
        if (!$this->tableExists('master.detraccion_service_codes')) {
            return [];
        }

        return DB::table('master.detraccion_service_codes')
            ->select('id', 'code', 'name', 'rate_percent')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get()
            ->map(function ($row) {
                return [
                    'id'           => (int) $row->id,
                    'code'         => (string) $row->code,
                    'name'         => (string) $row->name,
                    'rate_percent' => (float) $row->rate_percent,
                ];
            })
            ->values()
            ->all();
    }

    public function supplierAutocomplete(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $search = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 12);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 30) {
            $limit = 30;
        }

        $this->ensurePurchaseSuppliersTable();

        $query = DB::table('inventory.purchase_suppliers')
            ->select(['id', 'doc_type', 'doc_number', 'legal_name', 'address', 'source'])
            ->where('company_id', $companyId)
            ->orderByRaw('COALESCE(last_used_at, updated_at, created_at) DESC')
            ->limit($limit);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $normalizedDoc = preg_replace('/\D+/', '', $search);

            $query->where(function ($nested) use ($like, $normalizedDoc) {
                $nested->where('doc_number', 'ilike', $like)
                    ->orWhere('legal_name', 'ilike', $like)
                    ->orWhere('address', 'ilike', $like);

                if ($normalizedDoc !== '') {
                    $nested->orWhereRaw("REGEXP_REPLACE(COALESCE(doc_number, ''), '\\D', '', 'g') ILIKE ?", ['%' . $normalizedDoc . '%']);
                }
            });
        }

        $rows = $query
            ->get()
            ->map(function ($row) {
                return $this->supplierSuggestionFromRow($row);
            })
            ->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function resolveSupplierByDocument(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $document = preg_replace('/\D+/', '', (string) $request->query('document', ''));
        if (!is_string($document)) {
            $document = '';
        }

        if ($document === '' || !in_array(strlen($document), [8, 11], true)) {
            return response()->json([
                'message' => 'Debe enviar un DNI (8) o RUC (11) valido.',
            ], 422);
        }

        $this->ensurePurchaseSuppliersTable();

        $existing = $this->fetchSupplierRowByDocument($companyId, $document);
        if ($existing) {
            return response()->json(array_merge(
                $this->supplierSuggestionFromRow($existing),
                ['message' => 'Proveedor encontrado en base local.']
            ));
        }

        $isDni = strlen($document) === 8;
        $source = $isDni ? 'reniec' : 'sunat';

        try {
            if ($isDni) {
                $response = Http::timeout(10)
                    ->acceptJson()
                    ->get('https://mundosoftperu.com/reniec/consulta_reniec.php', ['dni' => $document]);

                if (!$response->ok()) {
                    return response()->json(['message' => 'No se pudo consultar RENIEC.'], 502);
                }

                $json = $response->json();
                if (!is_array($json) || !isset($json[0]) || (string) $json[0] !== $document) {
                    return response()->json(['message' => 'Numero no existe en RENIEC.'], 404);
                }

                $fullName = trim(implode(' ', array_filter([
                    (string) ($json[2] ?? ''),
                    (string) ($json[3] ?? ''),
                    (string) ($json[1] ?? ''),
                ])));

                if ($fullName === '') {
                    return response()->json(['message' => 'RENIEC no devolvio nombre valido.'], 404);
                }

                $this->upsertPurchaseSupplier($companyId, [
                    'doc_type' => 'DNI',
                    'doc_number' => $document,
                    'legal_name' => $fullName,
                    'address' => null,
                    'source' => $source,
                ]);

                $saved = $this->fetchSupplierRowByDocument($companyId, $document);
                return response()->json(array_merge(
                    $this->supplierSuggestionFromRow($saved),
                    ['message' => 'Proveedor consultado y registrado correctamente.']
                ));
            }

            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://mundosoftperu.com/sunat/sunat/consulta.php', ['nruc' => $document]);

            if (!$response->ok()) {
                return response()->json(['message' => 'No se pudo consultar SUNAT.'], 502);
            }

            $json = $response->json();
            $result  = is_array($json) ? ($json['result'] ?? null) : null;
            $ruc     = is_array($result) ? (string) ($result['RUC'] ?? '') : '';
            $razon   = is_array($result) ? trim((string) ($result['RazonSocial'] ?? '')) : '';
            $direccion = is_array($result) ? trim((string) ($result['Direccion'] ?? '')) : '';

            if ($ruc !== $document || $razon === '') {
                return response()->json(['message' => 'Numero no existe en SUNAT.'], 404);
            }

            $this->upsertPurchaseSupplier($companyId, [
                'doc_type' => 'RUC',
                'doc_number' => $document,
                'legal_name' => $razon,
                'address' => $direccion !== '' ? $direccion : null,
                'source' => $source,
            ]);

            $saved = $this->fetchSupplierRowByDocument($companyId, $document);
            return response()->json(array_merge(
                $this->supplierSuggestionFromRow($saved),
                ['message' => 'Proveedor consultado y registrado correctamente.']
            ));
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al consultar el padron: ' . $e->getMessage()], 500);
        }
    }

    private function ensurePurchaseSuppliersTable(): void
    {
        if ($this->tableExists('inventory.purchase_suppliers')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS inventory.purchase_suppliers (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                doc_type VARCHAR(3) NOT NULL,
                doc_number VARCHAR(20) NOT NULL,
                legal_name VARCHAR(255) NOT NULL,
                address VARCHAR(255) NULL,
                source VARCHAR(20) NULL,
                created_at TIMESTAMP NULL DEFAULT NOW(),
                updated_at TIMESTAMP NULL DEFAULT NOW(),
                last_used_at TIMESTAMP NULL DEFAULT NOW(),
                CONSTRAINT purchase_suppliers_company_doc_unique UNIQUE (company_id, doc_number)
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS purchase_suppliers_company_name_idx ON inventory.purchase_suppliers (company_id, legal_name)');
    }

    private function fetchSupplierRowByDocument(int $companyId, string $document)
    {
        return DB::table('inventory.purchase_suppliers')
            ->select(['id', 'doc_type', 'doc_number', 'legal_name', 'address', 'source'])
            ->where('company_id', $companyId)
            ->where('doc_number', $document)
            ->first();
    }

    private function upsertPurchaseSupplier(int $companyId, array $data): void
    {
        DB::statement(
            'INSERT INTO inventory.purchase_suppliers (company_id, doc_type, doc_number, legal_name, address, source, created_at, updated_at, last_used_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW()) '
            . 'ON CONFLICT (company_id, doc_number) DO UPDATE SET '
            . 'doc_type = EXCLUDED.doc_type, legal_name = EXCLUDED.legal_name, address = EXCLUDED.address, source = EXCLUDED.source, updated_at = NOW(), last_used_at = NOW()',
            [
                $companyId,
                (string) ($data['doc_type'] ?? ''),
                (string) ($data['doc_number'] ?? ''),
                (string) ($data['legal_name'] ?? ''),
                $data['address'] ?? null,
                $data['source'] ?? null,
            ]
        );
    }

    private function supplierSuggestionFromRow($row): array
    {
        return [
            'id' => isset($row->id) ? (int) $row->id : 0,
            'doc_type' => isset($row->doc_type) ? (string) $row->doc_type : null,
            'doc_number' => isset($row->doc_number) ? (string) $row->doc_number : '',
            'name' => isset($row->legal_name) ? (string) $row->legal_name : '',
            'address' => isset($row->address) ? (string) $row->address : null,
            'source' => isset($row->source) ? (string) $row->source : 'local',
        ];
    }
}
