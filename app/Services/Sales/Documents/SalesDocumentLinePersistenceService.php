<?php

namespace App\Services\Sales\Documents;

use App\Domain\Sales\Repositories\CommercialDocumentItemLotRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentPaymentRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SalesDocumentLinePersistenceService
{
    public function __construct(
        private SalesStockProjectionService $stockProjectionService,
        private CommercialDocumentItemRepositoryInterface $itemRepository,
        private CommercialDocumentItemLotRepositoryInterface $lotRepository,
        private CommercialDocumentPaymentRepositoryInterface $paymentRepository
    ) {
    }

    public function persistItemsAndStockMovements(
        array $processedItems,
        array $payload,
        int $stockDirection,
        int $companyId,
        ?int $warehouseId,
        array $settings,
        int $documentId,
        object $authUser,
        $resolvedIssueAt,
        int $nextNumber
    ): void {
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
                $lotsToCreate = [];
                foreach ($processedItem['lots'] as $lot) {
                    $lotsToCreate[] = [
                        'document_item_id' => $documentItemId,
                        'lot_id' => $lot['lot_id'],
                        'qty' => $lot['qty'],
                        'created_at' => now(),
                    ];
                }
                $this->lotRepository->create($lotsToCreate);
            }

            if ($processedItem['should_apply_stock']) {
                $lineDeltaBase = $stockDirection * (float) $processedItem['qty_base'];
                $product = $processedItem['product'];

                $this->stockProjectionService->applyCurrentStockDelta(
                    $companyId,
                    (int) $warehouseId,
                    (int) $product->id,
                    $lineDeltaBase,
                    (bool) $settings['allow_negative_stock']
                );

                $payloadUnitCost = isset($item['unit_cost']) && (float) $item['unit_cost'] > 0
                    ? (float) $item['unit_cost']
                    : null;

                $docKindLabels = ['INVOICE' => 'Factura', 'RECEIPT' => 'Boleta', 'CREDIT_NOTE' => 'Nota Credito', 'DEBIT_NOTE' => 'Nota Debito', 'QUOTATION' => 'Cotizacion', 'SALES_ORDER' => 'Pedido'];
                $docNote = 'Doc ' . ($docKindLabels[$payload['document_kind']] ?? $payload['document_kind']) . ' ' . $payload['series'] . '-' . $nextNumber;

                if (!empty($processedItem['lots'])) {
                    foreach ($processedItem['lots'] as $lot) {
                        $lotDeltaBase = $stockDirection * (float) $lot['qty_base'];

                        $this->stockProjectionService->applyLotStockDelta(
                            $companyId,
                            (int) $warehouseId,
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
                            'warehouse_id' => (int) $warehouseId,
                            'product_id' => (int) $product->id,
                            'lot_id' => (int) $lot['lot_id'],
                            'movement_type' => $stockDirection > 0 ? 'IN' : 'OUT',
                            'quantity' => round(abs($lotDeltaBase), 8),
                            'unit_cost' => $ledgerUnitCost,
                            'ref_type' => 'COMMERCIAL_DOCUMENT',
                            'ref_id' => $documentId,
                            'notes' => $docNote,
                            'moved_at' => $resolvedIssueAt,
                            'created_by' => $authUser->id,
                        ]);
                    }
                } else {
                    if ($payloadUnitCost !== null) {
                        $ledgerUnitCost = $payloadUnitCost;
                    } elseif ($stockDirection < 0) {
                        $ledgerUnitCost = (float) (DB::table('inventory.products')
                            ->where('id', (int) $product->id)
                            ->value('cost_price') ?? 0);
                    } else {
                        $ledgerUnitCost = 0.0;
                    }

                    DB::table('inventory.inventory_ledger')->insert([
                        'company_id' => $companyId,
                        'warehouse_id' => (int) $warehouseId,
                        'product_id' => (int) $product->id,
                        'lot_id' => null,
                        'movement_type' => $stockDirection > 0 ? 'IN' : 'OUT',
                        'quantity' => round(abs($lineDeltaBase), 8),
                        'unit_cost' => $ledgerUnitCost,
                        'ref_type' => 'COMMERCIAL_DOCUMENT',
                        'ref_id' => $documentId,
                        'notes' => $docNote,
                        'moved_at' => $resolvedIssueAt,
                        'created_by' => $authUser->id,
                    ]);
                }
            }

            $lineNo++;
        }
    }

    public function persistPayments(int $documentId, array $payments): void
    {
        foreach ($payments as $payment) {
            $this->paymentRepository->create([
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
}
