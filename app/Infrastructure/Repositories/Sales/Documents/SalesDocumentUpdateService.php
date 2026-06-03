<?php

namespace App\Infrastructure\Repositories\Sales\Documents;

use App\Application\Commands\Sales\UpdateCommercialDocumentDraftCommand;
use App\Domain\Sales\Entities\CommercialDocumentEntity;
use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemLotRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentPaymentRepositoryInterface;
use App\Services\Sales\Documents\SalesDocumentException;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

                $requestedPaymentsProvided = array_key_exists('payments', $payload) && is_array($payload['payments']);
                $requestedPayments = $requestedPaymentsProvided
                    ? $this->normalizePaymentsPayload($payload['payments'])
                    : [];
                $currentPayments = $this->loadCurrentPayments($documentId);
                $shouldSyncPayments = $requestedPaymentsProvided || !empty($payload['items']);

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
                        if ($productId !== null && $productId <= 0) {
                            $productId = null;
                        }
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
                    $currentPayments = [];

                    $stockDirection = CommercialDocumentPolicy::stockDirectionForDocument($documentKind);
                    $lineNo = 1;
                    foreach ($processedItems as $processedItem) {
                        $item = $processedItem['raw'];
                        $itemSubtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : ((float) $item['qty'] * (float) $item['unit_price']);
                        $itemTax = isset($item['tax_total']) ? (float) $item['tax_total'] : 0.0;
                        $itemDiscount = isset($item['discount_total']) ? (float) $item['discount_total'] : 0.0;
                        $itemTotal = isset($item['total']) ? (float) $item['total'] : ($itemSubtotal + $itemTax - $itemDiscount);
                        $commercialCostFactor = $itemSubtotal > 0 && $itemTotal > 0
                            ? max(1.0, $itemTotal / $itemSubtotal)
                            : 1.0;
                        $hasInventoryStock = true;
                        if (
                            $stockDirection < 0
                            && $warehouseId !== null
                            && $processedItem['product']
                            && (bool) ($processedItem['product']->is_stockable ?? false)
                        ) {
                            $currentProjectedStock = $this->stockProjectionService->projectedCurrentStock(
                                $companyId,
                                (int) $warehouseId,
                                (int) $processedItem['product']->id
                            );
                            $hasInventoryStock = $currentProjectedStock > 0.00000001;
                        }

                        if (
                            isset($item['unit_cost'])
                            && (float) $item['unit_cost'] > 0
                            && $stockDirection < 0
                            && (bool) ($processedItem['product']->is_stockable ?? false)
                            && !$hasInventoryStock
                        ) {
                            $item['unit_cost'] = null;
                        }

                        $historicalUnitCost = 0.0;
                        if (isset($item['unit_cost']) && (float) $item['unit_cost'] > 0) {
                            $historicalUnitCost = (float) $item['unit_cost'];
                        } elseif (!empty($processedItem['lots'])) {
                            $lotCostTotal = 0.0;
                            $lotQtyTotal = 0.0;
                            foreach ($processedItem['lots'] as $lot) {
                                $lotQty = (float) ($lot['qty'] ?? 0);
                                if ($lotQty <= 0) {
                                    continue;
                                }

                                $lotUnitCost = (float) ($lot['unit_cost'] ?? 0);
                                if ($lotUnitCost <= 0 && $hasInventoryStock && isset($processedItem['product']->cost_price)) {
                                    $lotUnitCost = (float) $processedItem['product']->cost_price;
                                    if ($lotUnitCost > 0) {
                                        $lotUnitCost = $lotUnitCost / $commercialCostFactor;
                                    }
                                }

                                $lotCostTotal += $lotUnitCost * $lotQty;
                                $lotQtyTotal += $lotQty;
                            }

                            if ($lotQtyTotal > 0) {
                                $historicalUnitCost = $lotCostTotal / $lotQtyTotal;
                            }
                        } elseif (
                            $stockDirection < 0
                            && (bool) ($processedItem['product']->is_stockable ?? false)
                            && $hasInventoryStock
                            && isset($processedItem['product']->cost_price)
                        ) {
                            $historicalUnitCost = (float) $processedItem['product']->cost_price;
                            if ($historicalUnitCost > 0) {
                                $historicalUnitCost = $historicalUnitCost / $commercialCostFactor;
                            }
                        }

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
                            'unit_cost' => round(max(0.0, $historicalUnitCost), 8),
                            'wholesale_discount_percent' => $item['wholesale_discount_percent'] ?? 0,
                            'price_source' => $item['price_source'] ?? 'MANUAL',
                            'discount_total' => round($itemDiscount, 2),
                            'tax_total' => round($itemTax, 2),
                            'subtotal' => round($itemSubtotal, 2),
                            'total' => round($itemTotal, 2),
                            'metadata' => $item['metadata'] ?? null,
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

                if (empty($payload['items']) && $shouldSyncPayments) {
                    $this->paymentRepository->deleteByDocumentId($documentId);
                }

                $effectivePayments = $shouldSyncPayments
                    ? ($requestedPaymentsProvided ? $requestedPayments : $currentPayments)
                    : $currentPayments;

                if ($documentStatus === 'ISSUED' && $shouldSyncPayments && empty($effectivePayments)) {
                    $fallbackPaymentMethodId = array_key_exists('payment_method_id', $payload)
                        ? ($payload['payment_method_id'] !== null ? (int) $payload['payment_method_id'] : null)
                        : ($document->payment_method_id !== null ? (int) $document->payment_method_id : null);

                    if ($fallbackPaymentMethodId !== null && $fallbackPaymentMethodId > 0 && (float) $totals['total'] > 0) {
                        $effectivePayments = [[
                            'payment_method_id' => $fallbackPaymentMethodId,
                            'amount' => round((float) $totals['total'], 2),
                            'status' => 'PAID',
                            'paid_at' => now()->toDateTimeString(),
                            'due_at' => null,
                            'notes' => null,
                        ]];
                    }
                }

                $paymentSummary = $this->summarizePaymentsForUpdate($effectivePayments);
                $paidTotal = $paymentSummary['paid_total'];
                $balanceDue = max(0.0, round((float) $totals['total'] - $paidTotal, 2));

                $metadataUpdates = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                $updatedMetadata = array_merge($currentMetadata, $metadataUpdates, [
                    'cash_register_id' => $cashRegisterId !== null ? (int) $cashRegisterId : null,
                    'last_manual_update_by' => (int) $authUser->id,
                    'last_manual_update_at' => now()->toDateTimeString(),
                ]);

                $updatedMetadata['payment_breakdown'] = $this->buildPaymentBreakdown($effectivePayments);

                if (array_key_exists('document_kind', $payload) || array_key_exists('document_kind_id', $payload)) {
                    $requestedKind = strtoupper(trim((string) ($payload['document_kind'] ?? $document->document_kind)));
                    $currentKind = strtoupper(trim((string) $document->document_kind));
                    if ($requestedKind !== '' && $requestedKind !== $currentKind) {
                        throw new SalesDocumentException('No se permite cambiar el tipo de comprobante en la edicion de documento.');
                    }
                }

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

                if (array_key_exists('document_kind_id', $payload) && $payload['document_kind_id'] !== null) {
                    $changes['document_kind_id'] = (int) $payload['document_kind_id'];
                }

                if (!empty($payload['items'])) {
                    $changes['subtotal'] = $totals['subtotal'];
                    $changes['tax_total'] = $totals['tax_total'];
                    $changes['discount_total'] = $totals['discount_total'];
                    $changes['total'] = $totals['total'];
                }

                if (!empty($payload['items']) || $shouldSyncPayments) {
                    $changes['paid_total'] = $paidTotal;
                    $changes['balance_due'] = $balanceDue;
                }

                $this->documentRepository->update($documentId, $companyId, $changes);

                if ($shouldSyncPayments) {
                    $this->persistPayments($documentId, $effectivePayments);
                    $this->syncCashMovementsForEditedDocument(
                        $companyId,
                        $documentId,
                        $branchId !== null ? (int) $branchId : null,
                        $cashRegisterId !== null ? (int) $cashRegisterId : null,
                        (string) $document->document_kind,
                        (string) $document->series,
                        (int) $document->number,
                        $paidTotal,
                        (int) $authUser->id,
                        $effectivePayments
                    );
                }

                return [
                    'id' => $documentId,
                    'status' => (string) $document->status,
                    'total' => !empty($payload['items']) ? $totals['total'] : (float) $document->total,
                    'updated_items' => !empty($payload['items']),
                ];
            });
        } catch (\RuntimeException $e) {
            throw new SalesDocumentException($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('sales.document.update_draft.unexpected_error', [
                'company_id' => $companyId,
                'document_id' => $documentId,
                'message' => $e->getMessage(),
            ]);

            throw new SalesDocumentException('No se pudo actualizar el comprobante por un error interno.', 422);
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
            $itemSubtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : ((float) $item['qty'] * (float) $item['unit_price']);
            $itemTotal = isset($item['total']) ? (float) $item['total'] : ($itemSubtotal + (float) ($item['tax_total'] ?? 0) - (float) ($item['discount_total'] ?? 0));
            $commercialCostFactor = $itemSubtotal > 0 && $itemTotal > 0
                ? max(1.0, $itemTotal / $itemSubtotal)
                : 1.0;

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
            $hasInventoryStock = $this->stockProjectionService->projectedCurrentStock(
                $companyId,
                $warehouseId,
                (int) $product->id
            ) > 0.00000001;
            if ($payloadUnitCost !== null && $stockDirection < 0 && !$hasInventoryStock) {
                $payloadUnitCost = null;
            }
            if ($payloadUnitCost === null && $stockDirection < 0 && $hasInventoryStock) {
                $payloadUnitCost = (float) ($product->cost_price ?? 0);
                if ($payloadUnitCost > 0) {
                    $payloadUnitCost = $payloadUnitCost / $commercialCostFactor;
                }
            }

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
                        $ledgerUnitCost = (float) ($lot['unit_cost'] ?? 0);
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
                if ($ledgerUnitCost > 0) {
                    $ledgerUnitCost = $ledgerUnitCost / $commercialCostFactor;
                }
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

    private function normalizePaymentsPayload(array $payments): array
    {
        return collect($payments)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) {
                $status = strtoupper(trim((string) ($row['status'] ?? 'PAID')));
                if (!in_array($status, ['PENDING', 'PAID', 'CANCELED'], true)) {
                    $status = 'PAID';
                }

                return [
                    'payment_method_id' => isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : 0,
                    'amount' => round((float) ($row['amount'] ?? 0), 2),
                    'status' => $status,
                    'paid_at' => $row['paid_at'] ?? null,
                    'due_at' => $row['due_at'] ?? null,
                    'notes' => isset($row['notes']) && trim((string) $row['notes']) !== ''
                        ? trim((string) $row['notes'])
                        : null,
                ];
            })
            ->filter(fn (array $row) => $row['payment_method_id'] > 0 && $row['amount'] > 0)
            ->values()
            ->all();
    }

    private function loadCurrentPayments(int $documentId): array
    {
        return DB::table('sales.commercial_document_payments')
            ->where('document_id', $documentId)
            ->orderBy('id')
            ->get([
                'payment_method_id',
                'amount',
                'status',
                'paid_at',
                'due_at',
                'notes',
            ])
            ->map(function ($row) {
                return [
                    'payment_method_id' => (int) ($row->payment_method_id ?? 0),
                    'amount' => round((float) ($row->amount ?? 0), 2),
                    'status' => strtoupper(trim((string) ($row->status ?? 'PENDING'))),
                    'paid_at' => $row->paid_at,
                    'due_at' => $row->due_at,
                    'notes' => $row->notes,
                ];
            })
            ->filter(fn (array $row) => $row['payment_method_id'] > 0 && $row['amount'] > 0)
            ->values()
            ->all();
    }

    private function summarizePaymentsForUpdate(array $payments): array
    {
        $paidTotal = collect($payments)
            ->filter(function (array $row) {
                return strtoupper(trim((string) ($row['status'] ?? 'PENDING'))) === 'PAID';
            })
            ->sum('amount');

        return [
            'paid_total' => round((float) $paidTotal, 2),
        ];
    }

    private function buildPaymentBreakdown(array $payments): array
    {
        $methodIds = collect($payments)
            ->map(fn (array $row) => (int) ($row['payment_method_id'] ?? 0))
            ->filter(fn (int $methodId) => $methodId > 0)
            ->unique()
            ->values()
            ->all();

        $methodMap = [];
        if (!empty($methodIds)) {
            $methodMap = DB::table('master.payment_types')
                ->whereIn('id', $methodIds)
                ->pluck('name', 'id')
                ->map(fn ($name) => trim((string) $name))
                ->all();
        }

        return collect($payments)
            ->map(function (array $payment) use ($methodMap) {
                $methodId = (int) ($payment['payment_method_id'] ?? 0);

                return [
                    'payment_method_id' => $methodId > 0 ? $methodId : null,
                    'payment_method_name' => $methodId > 0
                        ? ((string) ($methodMap[$methodId] ?? ('Metodo #' . $methodId)))
                        : null,
                    'amount' => round((float) ($payment['amount'] ?? 0), 2),
                    'status' => strtoupper(trim((string) ($payment['status'] ?? 'PENDING'))),
                    'paid_at' => $payment['paid_at'] ?? null,
                    'due_at' => $payment['due_at'] ?? null,
                    'notes' => $payment['notes'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function persistPayments(int $documentId, array $payments): void
    {
        if (empty($payments)) {
            return;
        }

        $timestamp = now();
        $rows = collect($payments)
            ->map(function (array $row) use ($documentId, $timestamp) {
                return [
                    'document_id' => $documentId,
                    'payment_method_id' => (int) $row['payment_method_id'],
                    'amount' => round((float) $row['amount'], 2),
                    'due_at' => $row['due_at'] ?? null,
                    'paid_at' => $row['paid_at'] ?? null,
                    'status' => strtoupper(trim((string) ($row['status'] ?? 'PENDING'))),
                    'notes' => $row['notes'] ?? null,
                    'created_at' => $timestamp,
                ];
            })
            ->all();

        $this->paymentRepository->createBatch($rows);
    }

    private function syncCashMovementsForEditedDocument(
        int $companyId,
        int $documentId,
        ?int $branchId,
        ?int $cashRegisterId,
        string $documentKind,
        string $series,
        int $number,
        float $paidTotal,
        int $userId,
        array $payments
    ): void {
        if (!$this->tableExists('sales.cash_movements') || !$this->tableExists('sales.cash_sessions')) {
            return;
        }

        $existingRows = DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->orderBy('id')
            ->get();

        if ($existingRows->isEmpty()) {
            return;
        }

        $sessionId = (int) ($existingRows->first()->cash_session_id ?? 0);
        if ($sessionId <= 0) {
            return;
        }

        DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->delete();

        if ($paidTotal > 0) {
            $paidBreakdown = collect($payments)
                ->filter(function (array $row) {
                    return strtoupper(trim((string) ($row['status'] ?? 'PENDING'))) === 'PAID'
                        && (float) ($row['amount'] ?? 0) > 0;
                })
                ->groupBy(fn (array $row) => (int) ($row['payment_method_id'] ?? 0))
                ->map(function ($group, $methodId) {
                    return [
                        'payment_method_id' => (int) $methodId > 0 ? (int) $methodId : null,
                        'amount' => round((float) $group->sum('amount'), 2),
                    ];
                })
                ->filter(fn (array $row) => (float) $row['amount'] > 0)
                ->values();

            if ($paidBreakdown->isEmpty()) {
                $paidBreakdown = collect([[
                    'payment_method_id' => null,
                    'amount' => round($paidTotal, 2),
                ]]);
            }

            $labelMap = [
                'INVOICE' => 'Factura',
                'RECEIPT' => 'Boleta',
                'CREDIT_NOTE' => 'Nota Credito',
                'DEBIT_NOTE' => 'Nota Debito',
                'QUOTATION' => 'Cotizacion',
                'SALES_ORDER' => 'Pedido',
            ];
            $description = 'Cobro doc ' . ($labelMap[strtoupper(trim($documentKind))] ?? $documentKind) . ' ' . $series . '-' . $number;
            $movementAt = now();

            $insertRows = $paidBreakdown->map(function (array $row) use ($companyId, $branchId, $cashRegisterId, $sessionId, $description, $documentId, $userId, $movementAt) {
                return [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'cash_register_id' => $cashRegisterId,
                    'cash_session_id' => $sessionId,
                    'movement_type' => 'INCOME',
                    'payment_method_id' => $row['payment_method_id'],
                    'amount' => (float) $row['amount'],
                    'description' => $description,
                    'notes' => $description,
                    'ref_type' => 'COMMERCIAL_DOCUMENT',
                    'ref_id' => $documentId,
                    'created_by' => $userId,
                    'user_id' => $userId,
                    'movement_at' => $movementAt,
                    'created_at' => $movementAt,
                ];
            })->all();

            DB::table('sales.cash_movements')->insert($insertRows);
        }

        $totalIn = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $sessionId)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $sessionId)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        $openingBalance = (float) (DB::table('sales.cash_sessions')
            ->where('id', $sessionId)
            ->value('opening_balance') ?? 0);

        DB::table('sales.cash_sessions')
            ->where('id', $sessionId)
            ->update([
                'expected_balance' => round($openingBalance + $totalIn - $totalOut, 4),
            ]);
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

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = strpos($qualifiedTable, '.') === false ? ['public', $qualifiedTable] : explode('.', $qualifiedTable, 2);
        $row = DB::selectOne('select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present', [$schema, $table]);

        return isset($row->present) && (bool) $row->present;
    }

}
