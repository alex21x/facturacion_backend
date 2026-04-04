<?php

namespace App\Services\Sales\Documents;

use App\Application\Commands\Sales\UpdateCommercialDocumentDraftCommand;
use App\Domain\Sales\Entities\CommercialDocumentEntity;
use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemLotRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentPaymentRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesDocumentUpdateService
{
    public function __construct(
        private SalesDocumentSupportService $support,
        private SalesStockProjectionService $stockProjectionService,
        private CommercialDocumentRepositoryInterface $documentRepository,
        private CommercialDocumentItemRepositoryInterface $itemRepository,
        private CommercialDocumentItemLotRepositoryInterface $lotRepository,
        private CommercialDocumentPaymentRepositoryInterface $paymentRepository
    ) {
    }

    public function updateDraft(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        return $this->updateDraftFromCommand(
            UpdateCommercialDocumentDraftCommand::fromInput($authUser, $companyId, $documentId, $payload)
        );
    }

    public function updateDraftFromCommand(UpdateCommercialDocumentDraftCommand $command): array
    {
        $authUser = $command->authUser;
        $companyId = $command->companyId;
        $documentId = $command->documentId;
        $payload = $command->payload;

        $document = $this->documentRepository->findById($documentId, $companyId);

        if (!$document) {
            throw new SalesDocumentException('Documento no encontrado', 404);
        }

        $documentEntity = CommercialDocumentEntity::fromPersistence($document);

        $currentMetadata = $this->support->decodeDocumentMetadata($document->metadata);
        $documentStatus = strtoupper((string) ($document->status ?? ''));
        $documentKind = strtoupper((string) ($document->document_kind ?? ''));
        $sunatStatus = strtoupper((string) ($currentMetadata['sunat_status'] ?? ''));
        $isTributaryDocument = in_array($documentKind, ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'], true);
        $isCommercialPreDocument = in_array($documentKind, ['QUOTATION', 'SALES_ORDER'], true);
        $isFinalSunatStatus = in_array($sunatStatus, ['ACCEPTED', 'ANULADO', 'VOIDED'], true);

        $featureBranchId = $document->branch_id !== null ? (int) $document->branch_id : null;
        $allowDraftEdit = $this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $featureBranchId, 'SALES_ALLOW_DRAFT_EDIT', true);

        if ($documentStatus === 'DRAFT') {
            try {
                $documentEntity->assertCanEditDraft();
            } catch (DomainException $e) {
                throw new SalesDocumentException($e->getMessage(), 422);
            }

            if (!$allowDraftEdit) {
                throw new SalesDocumentException('La edicion de borradores esta deshabilitada para este contexto.', 403);
            }
        } elseif ($documentStatus === 'ISSUED' && $isTributaryDocument && !$isFinalSunatStatus) {
            // Allowed issued tributary edit before SUNAT final state.
        } elseif ($isCommercialPreDocument && in_array($documentStatus, ['APPROVED', 'ISSUED'], true) && $allowDraftEdit) {
            // Allowed for commercial pre-documents while they have no active conversions.
        } else {
            throw new SalesDocumentException('Solo se pueden editar borradores o comprobantes emitidos sin estado SUNAT final.', 422);
        }

        if ($this->support->hasActiveChildConversions($companyId, $documentId)) {
            throw new SalesDocumentException('No se puede editar: el documento ya tiene conversiones activas', 422);
        }

        $branchId = array_key_exists('branch_id', $payload)
            ? $payload['branch_id']
            : ($document->branch_id !== null ? (int) $document->branch_id : null);
        $warehouseId = array_key_exists('warehouse_id', $payload)
            ? $payload['warehouse_id']
            : ($document->warehouse_id !== null ? (int) $document->warehouse_id : null);
        $cashRegisterId = array_key_exists('cash_register_id', $payload)
            ? $payload['cash_register_id']
            : (isset($currentMetadata['cash_register_id']) && $currentMetadata['cash_register_id'] !== null
                ? (int) $currentMetadata['cash_register_id']
                : null);

        if ($branchId !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

            if (!$branchExists) {
                throw new SalesDocumentException('Invalid branch scope', 422);
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
                throw new SalesDocumentException('Invalid warehouse scope', 422);
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
                throw new SalesDocumentException('Invalid cash register scope', 422);
            }
        }

        try {
            return DB::transaction(function () use (
                $payload,
                $authUser,
                $companyId,
                $document,
                $documentId,
                $branchId,
                $warehouseId,
                $cashRegisterId,
                $currentMetadata,
                $documentStatus,
                $isTributaryDocument,
                $documentKind
            ) {
                $totals = [
                    'subtotal' => (float) $document->subtotal,
                    'tax_total' => (float) $document->tax_total,
                    'discount_total' => (float) $document->discount_total,
                    'total' => (float) $document->total,
                ];

                if (!empty($payload['items'])) {
                    $productIds = collect($payload['items'])
                        ->pluck('product_id')
                        ->filter(function ($rowId) {
                            return $rowId !== null;
                        })
                        ->map(function ($rowId) {
                            return (int) $rowId;
                        })
                        ->unique()
                        ->values();

                    $productMap = DB::table('inventory.products')
                        ->select('id', 'name', 'unit_id', 'is_stockable', 'status', 'cost_price')
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
                        ->filter(function ($rowId) {
                            return $rowId !== null;
                        })
                        ->map(function ($rowId) {
                            return (int) $rowId;
                        })
                        ->unique()
                        ->values();

                    $lotMap = DB::table('inventory.product_lots')
                        ->select('id', 'warehouse_id', 'product_id', 'status')
                        ->where('company_id', $companyId)
                        ->whereIn('id', $allLotIds->all())
                        ->get()
                        ->keyBy('id');

                    $processedItems = [];

                    foreach ($payload['items'] as $index => $item) {
                        $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
                        $product = $productId ? $productMap->get($productId) : null;

                        if ($productId !== null && !$product) {
                            throw new SalesDocumentException('Product not found for line ' . ($index + 1));
                        }

                        if ($productId !== null && (int) $product->status !== 1) {
                            throw new SalesDocumentException('Product inactive for line ' . ($index + 1));
                        }

                        $itemUnitId = isset($item['unit_id']) ? (int) $item['unit_id'] : null;
                        if ($product && !$itemUnitId) {
                            $itemUnitId = (int) $product->unit_id;
                        }

                        $conversion = $this->support->resolveLineConversion($companyId, $product, $item, $itemUnitId);
                        $qtyBase = $conversion['qty_base'];
                        $conversionFactor = $conversion['conversion_factor'];

                        $itemLots = [];
                        $lotBaseQtyTotal = 0.0;

                        if (!empty($item['lots']) && is_array($item['lots'])) {
                            foreach ($item['lots'] as $lot) {
                                $lotId = (int) $lot['lot_id'];
                                $lotQty = (float) $lot['qty'];
                                $lotBaseQty = $lotQty * $conversionFactor;
                                $lotRow = $lotMap->get($lotId);

                                if (!$lotRow) {
                                    throw new SalesDocumentException('Lot not found for line ' . ($index + 1));
                                }

                                if ($product && (int) $lotRow->product_id !== (int) $product->id) {
                                    throw new SalesDocumentException('Lot does not belong to product for line ' . ($index + 1));
                                }

                                if ($warehouseId !== null && (int) $lotRow->warehouse_id !== (int) $warehouseId) {
                                    throw new SalesDocumentException('Lot does not belong to warehouse scope for line ' . ($index + 1));
                                }

                                $itemLots[] = [
                                    'lot_id' => $lotId,
                                    'qty' => $lotQty,
                                    'qty_base' => $lotBaseQty,
                                ];

                                $lotBaseQtyTotal += $lotBaseQty;
                            }

                            if (abs($lotBaseQtyTotal - $qtyBase) > 0.0001) {
                                throw new SalesDocumentException('Lot quantity mismatch for line ' . ($index + 1));
                            }
                        }

                        $shouldApplyStock = $documentStatus === 'ISSUED'
                            && $isTributaryDocument
                            && $product
                            && (bool) $product->is_stockable;

                        $processedItems[] = [
                            'raw' => $item,
                            'product' => $product,
                            'item_unit_id' => $itemUnitId,
                            'qty_base' => $qtyBase,
                            'conversion_factor' => $conversionFactor,
                            'base_unit_price' => $conversion['base_unit_price'],
                            'lots' => $itemLots,
                            'should_apply_stock' => $shouldApplyStock,
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

                    $shouldRebuildInventory = $documentStatus === 'ISSUED' && $isTributaryDocument;
                    $inventorySettings = $this->inventorySettingsForCompany($companyId);
                    $this->stockProjectionService->reset();

                    if ($shouldRebuildInventory) {
                        $this->reverseInventoryLedgerForDocumentEdit(
                            $companyId,
                            $documentId,
                            (int) $authUser->id,
                            now()->toDateTimeString(),
                            $inventorySettings
                        );
                    }

                    $this->documentRepository->deleteItemsAndPayments($documentId);

                    $lineNo = 1;
                    foreach ($processedItems as $processedItem) {
                        $item = $processedItem['raw'];
                        $itemSubtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : ((float) $item['qty'] * (float) $item['unit_price']);
                        $itemTax = isset($item['tax_total']) ? (float) $item['tax_total'] : 0.0;
                        $itemDiscount = isset($item['discount_total']) ? (float) $item['discount_total'] : 0.0;
                        $itemTotal = isset($item['total']) ? (float) $item['total'] : ($itemSubtotal + $itemTax - $itemDiscount);

                        $documentItemId = $this->itemRepository->create([
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
                                $this->lotRepository->create([
                                    'document_item_id' => $documentItemId,
                                    'lot_id' => $lot['lot_id'],
                                    'qty' => $lot['qty'],
                                    'created_at' => now(),
                                ]);
                            }
                        }

                        $lineNo++;
                    }

                    if ($shouldRebuildInventory) {
                        $this->applyInventoryLedgerForEditedDocument(
                            $companyId,
                            $documentId,
                            $warehouseId !== null ? (int) $warehouseId : null,
                            $documentKind,
                            $processedItems,
                            (int) $authUser->id,
                            now()->toDateTimeString(),
                            $inventorySettings
                        );
                    }

                    $totals = [
                        'subtotal' => round($subtotal, 2),
                        'tax_total' => round($taxTotal, 2),
                        'discount_total' => round($discountTotal, 2),
                        'total' => round($grandTotal, 2),
                    ];
                }

                $metadataUpdates = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                $updatedMetadata = array_merge($currentMetadata, $metadataUpdates, [
                    'cash_register_id' => $cashRegisterId !== null ? (int) $cashRegisterId : null,
                    'last_manual_update_by' => (int) $authUser->id,
                    'last_manual_update_at' => now()->toDateTimeString(),
                ]);

                if (!empty($payload['items']) && $documentStatus === 'ISSUED' && $isTributaryDocument) {
                    $updatedMetadata['inventory_edit_reapplied_by'] = (int) $authUser->id;
                    $updatedMetadata['inventory_edit_reapplied_at'] = now()->toDateTimeString();
                }

                $changes = [
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                    'customer_id' => array_key_exists('customer_id', $payload) ? (int) $payload['customer_id'] : (int) $document->customer_id,
                    'currency_id' => array_key_exists('currency_id', $payload) ? (int) $payload['currency_id'] : (int) $document->currency_id,
                    'payment_method_id' => array_key_exists('payment_method_id', $payload)
                        ? ($payload['payment_method_id'] !== null ? (int) $payload['payment_method_id'] : null)
                        : $document->payment_method_id,
                    'due_at' => array_key_exists('due_at', $payload) ? ($payload['due_at'] ?? null) : $document->due_at,
                    'notes' => array_key_exists('notes', $payload) ? ($payload['notes'] ?? null) : $document->notes,
                    'metadata' => json_encode($updatedMetadata),
                ];

                if (!empty($payload['items'])) {
                    $changes['subtotal'] = $totals['subtotal'];
                    $changes['tax_total'] = $totals['tax_total'];
                    $changes['discount_total'] = $totals['discount_total'];
                    $changes['total'] = $totals['total'];
                    $changes['paid_total'] = 0;
                    $changes['balance_due'] = $totals['total'];
                }

                $this->documentRepository->update($documentId, $companyId, $changes);

                return [
                    'id' => $documentId,
                    'status' => (string) $document->status,
                    'total' => !empty($payload['items']) ? $totals['total'] : (float) $document->total,
                    'updated_items' => !empty($payload['items']),
                ];
            });
        } catch (\RuntimeException $e) {
            throw new SalesDocumentException($e->getMessage(), 422);
        }
    }

    private function reverseInventoryLedgerForDocumentEdit(
        int $companyId,
        int $documentId,
        int $userId,
        string $movedAt,
        array $settings
    ): void {
        $rows = DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
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

            $this->stockProjectionService->applyCurrentStockDelta(
                $companyId,
                (int) $row->warehouse_id,
                (int) $row->product_id,
                $delta,
                (bool) $settings['allow_negative_stock']
            );

            if ($row->lot_id !== null) {
                $this->stockProjectionService->applyLotStockDelta(
                    $companyId,
                    (int) $row->warehouse_id,
                    (int) $row->product_id,
                    (int) $row->lot_id,
                    $delta,
                    (bool) $settings['allow_negative_stock']
                );
            }

            DB::table('inventory.inventory_ledger')->insert([
                'company_id' => $companyId,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
                'lot_id' => $row->lot_id !== null ? (int) $row->lot_id : null,
                'movement_type' => $reverseType,
                'quantity' => $qty,
                'unit_cost' => (float) ($row->unit_cost ?? 0),
                'ref_type' => 'COMMERCIAL_DOCUMENT_EDIT',
                'ref_id' => $documentId,
                'notes' => 'Reversa por edicion de doc comercial #' . $documentId,
                'moved_at' => $movedAt,
                'created_by' => $userId,
            ]);
        }

        DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
            ->delete();
    }

    private function applyInventoryLedgerForEditedDocument(
        int $companyId,
        int $documentId,
        ?int $warehouseId,
        string $documentKind,
        array $processedItems,
        int $userId,
        string $movedAt,
        array $settings
    ): void {
        $stockDirection = CommercialDocumentPolicy::stockDirectionForDocument($documentKind);
        if ($stockDirection === 0) {
            return;
        }

        $docKindLabels = [
            'INVOICE' => 'Factura',
            'RECEIPT' => 'Boleta',
            'CREDIT_NOTE' => 'Nota Credito',
            'DEBIT_NOTE' => 'Nota Debito',
        ];
        $docNote = 'Reaplicacion por edicion de ' . ($docKindLabels[$documentKind] ?? $documentKind) . ' #' . $documentId;

        foreach ($processedItems as $processedItem) {
            if (empty($processedItem['should_apply_stock'])) {
                continue;
            }

            if ($warehouseId === null) {
                throw new SalesDocumentException('Warehouse is required to recalculate stock for edited issued document', 422);
            }

            $item = $processedItem['raw'];
            $product = $processedItem['product'];
            $lineDeltaBase = $stockDirection * (float) $processedItem['qty_base'];

            $this->stockProjectionService->applyCurrentStockDelta(
                $companyId,
                $warehouseId,
                (int) $product->id,
                $lineDeltaBase,
                (bool) $settings['allow_negative_stock']
            );

            $payloadUnitCost = isset($item['unit_cost']) && (float) $item['unit_cost'] > 0
                ? (float) $item['unit_cost']
                : null;

            if (!empty($processedItem['lots'])) {
                foreach ($processedItem['lots'] as $lot) {
                    $lotDeltaBase = $stockDirection * (float) $lot['qty_base'];

                    $this->stockProjectionService->applyLotStockDelta(
                        $companyId,
                        $warehouseId,
                        (int) $product->id,
                        (int) $lot['lot_id'],
                        $lotDeltaBase,
                        (bool) $settings['allow_negative_stock']
                    );

                    if ($payloadUnitCost !== null) {
                        $ledgerUnitCost = $payloadUnitCost;
                    } elseif ($stockDirection < 0) {
                        $ledgerUnitCost = (float) (DB::table('inventory.product_lots')
                            ->where('id', (int) $lot['lot_id'])
                            ->value('unit_cost') ?? 0);
                    } else {
                        $ledgerUnitCost = 0.0;
                    }

                    DB::table('inventory.inventory_ledger')->insert([
                        'company_id' => $companyId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => (int) $product->id,
                        'lot_id' => (int) $lot['lot_id'],
                        'movement_type' => $stockDirection > 0 ? 'IN' : 'OUT',
                        'quantity' => round(abs($lotDeltaBase), 8),
                        'unit_cost' => $ledgerUnitCost,
                        'ref_type' => 'COMMERCIAL_DOCUMENT',
                        'ref_id' => $documentId,
                        'notes' => $docNote,
                        'moved_at' => $movedAt,
                        'created_by' => $userId,
                    ]);
                }
                continue;
            }

            if ($payloadUnitCost !== null) {
                $ledgerUnitCost = $payloadUnitCost;
            } elseif ($stockDirection < 0) {
                $ledgerUnitCost = (float) ($product->cost_price ?? 0);
            } else {
                $ledgerUnitCost = 0.0;
            }

            DB::table('inventory.inventory_ledger')->insert([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'product_id' => (int) $product->id,
                'lot_id' => null,
                'movement_type' => $stockDirection > 0 ? 'IN' : 'OUT',
                'quantity' => round(abs($lineDeltaBase), 8),
                'unit_cost' => $ledgerUnitCost,
                'ref_type' => 'COMMERCIAL_DOCUMENT',
                'ref_id' => $documentId,
                'notes' => $docNote,
                'moved_at' => $movedAt,
                'created_by' => $userId,
            ]);
        }
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')->where('company_id', $companyId)->first();

        if (!$row) {
            return [
                'allow_negative_stock' => false,
            ];
        }

        return [
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
        ];
    }

}
