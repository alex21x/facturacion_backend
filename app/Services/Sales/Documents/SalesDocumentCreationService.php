<?php

namespace App\Services\Sales\Documents;

use App\Application\DTOs\Sales\CreateCommercialDocumentResultDTO;
use App\Application\Commands\Sales\CreateCommercialDocumentCommand;
use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesDocumentCreationService
{
    public function __construct(
        private TaxBridgeService $taxBridgeService,
        private SalesDocumentSupportService $support,
        private SalesStockProjectionService $stockProjectionService,
        private SalesDocumentTaxMetadataService $taxMetadataService,
        private SalesDocumentPaymentMetadataService $paymentMetadataService,
        private SalesDocumentSeriesService $seriesService,
        private SalesDocumentItemPreparationService $itemPreparationService,
        private SalesDocumentNoteValidationService $noteValidationService,
        private SalesDocumentLinePersistenceService $linePersistenceService,
        private SalesDocumentCashPostingService $cashPostingService,
        private CommercialDocumentRepositoryInterface $documentRepository,
    )
    {
    }

    public function create(object $authUser, array $payload, int $companyId, $branchId, $warehouseId, $cashRegisterId): array
    {
        return $this->createFromCommand(
            CreateCommercialDocumentCommand::fromInput(
                $authUser,
                $payload,
                $companyId,
                $branchId !== null ? (int) $branchId : null,
                $warehouseId !== null ? (int) $warehouseId : null,
                $cashRegisterId !== null ? (int) $cashRegisterId : null
            )
        );
    }

    public function createFromCommand(CreateCommercialDocumentCommand $command): array
    {
        $authUser = $command->authUser;
        $payload = $command->payload;
        $companyId = $command->companyId;
        $branchId = $command->branchId;
        $warehouseId = $command->warehouseId;
        $cashRegisterId = $command->cashRegisterId;

        $this->stockProjectionService->reset();

        try {
            $result = DB::transaction(function () use ($payload, $authUser, $companyId, $branchId, $warehouseId, $cashRegisterId) {
                $isSellerToCashierMode = $this->isCommerceFeatureEnabledForContext($companyId, $branchId, 'SALES_SELLER_TO_CASHIER');
                $isPreDocument = in_array($payload['document_kind'], ['SALES_ORDER', 'QUOTATION'], true);
                $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                $isConversionFlow = array_key_exists('source_document_id', $metadata)
                    || strtoupper((string) ($metadata['conversion_origin'] ?? '')) === 'SALES_MODULE';
                $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
                $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

                if ($roleCode === '' && $roleProfile === '') {
                    $roleContext = $this->resolveAuthRoleContext((int) $authUser->id, $companyId);
                    $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
                    $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
                }

                if ($isSellerToCashierMode) {
                    if ($isPreDocument && $this->isCashierActor($roleProfile, $roleCode) && !$isConversionFlow) {
                        throw new SalesDocumentException('En este modo, caja no genera pedidos. Use Reporte para convertir pedidos pendientes.');
                    }

                    if (!$isPreDocument && $this->isSellerActor($roleProfile, $roleCode)) {
                        throw new SalesDocumentException('En este modo, vendedor no emite comprobantes finales. Debe generar pedido para caja.');
                    }
                }

                if ($isSellerToCashierMode && $isPreDocument && !$isConversionFlow) {
                    $payload['status'] = 'DRAFT';
                    $payload['payments'] = [];
                    $cashRegisterId = null;
                }

                $documentStatus = $payload['status'] ?? 'DRAFT';
                $stockDirection = CommercialDocumentPolicy::stockDirectionForDocument((string) $payload['document_kind']);
                $stockAlreadyDiscounted = false;

                if (array_key_exists('stock_already_discounted', $metadata)) {
                    $stockAlreadyDiscounted = filter_var($metadata['stock_already_discounted'], FILTER_VALIDATE_BOOLEAN);
                }

                $affectsStock = !$stockAlreadyDiscounted && CommercialDocumentPolicy::shouldAffectStock((string) $payload['document_kind'], (string) $documentStatus);
                $settings = $this->inventorySettingsForCompany($companyId);

                $nextNumber = $this->seriesService->reserveNextNumber(
                    $companyId,
                    (string) $payload['document_kind'],
                    (string) $payload['series'],
                    $branchId,
                    $warehouseId,
                    (int) $authUser->id
                );

                $processedItems = $this->itemPreparationService->prepareProcessedItems(
                    $companyId,
                    $warehouseId,
                    $payload['items'],
                    $affectsStock,
                    $stockDirection,
                    $settings
                );

                $totals = $this->itemPreparationService->calculateDocumentTotals($payload['items']);
                $subtotal = $totals->subtotal;
                $taxTotal = $totals->taxTotal;
                $discountTotal = $totals->discountTotal;
                $grandTotal = $totals->grandTotal;

                $this->noteValidationService->validateSourceAndAvailableAmount(
                    $payload,
                    $metadata,
                    $companyId,
                    $grandTotal
                );

                $metadata = $this->taxMetadataService->validateAndEnrich(
                    $metadata,
                    (string) $payload['document_kind'],
                    $grandTotal,
                    $companyId,
                    $branchId
                );

                $paymentSummary = $this->paymentMetadataService->summarizePayments($payload['payments'] ?? []);
                $paidTotal = $paymentSummary->paidTotal;
                $paymentTotal = $paymentSummary->paymentTotal;
                $pendingPayments = $paymentSummary->pendingPayments;

                $this->paymentMetadataService->assertIssuedDocumentPaymentConsistency(
                    $isPreDocument,
                    (string) $documentStatus,
                    $paymentTotal,
                    $grandTotal,
                    $pendingPayments
                );

                $metadata = $this->paymentMetadataService->enrichPaymentMetadata(
                    $metadata,
                    $pendingPayments,
                    $paidTotal,
                    $companyId,
                    $branchId
                );

                $payload['metadata'] = $metadata;

                $resolvedIssueAt = $this->resolveIssueAtForStorage($payload['issue_at'] ?? null);

                $documentId = $this->documentRepository->create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                    'document_kind' => $payload['document_kind'],
                    'series' => $payload['series'],
                    'number' => $nextNumber,
                    'issue_at' => $resolvedIssueAt,
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

                $this->linePersistenceService->persistItemsAndStockMovements(
                    $processedItems,
                    $payload,
                    $stockDirection,
                    $companyId,
                    $warehouseId,
                    $settings,
                    (int) $documentId,
                    $authUser,
                    $resolvedIssueAt,
                    (int) $nextNumber
                );

                if (!empty($payload['payments'])) {
                    $this->linePersistenceService->persistPayments((int) $documentId, $payload['payments']);
                }

                $this->cashPostingService->registerCashIncomeFromDocument(
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

                $resultDto = new CreateCommercialDocumentResultDTO(
                    id: (int) $documentId,
                    documentKind: (string) $payload['document_kind'],
                    series: (string) $payload['series'],
                    number: (int) $nextNumber,
                    total: round($grandTotal, 2),
                    paidTotal: round($paidTotal, 2),
                    balanceDue: round($grandTotal - $paidTotal, 2),
                    status: (string) $documentStatus,
                    branchId: $branchId,
                    warehouseId: $warehouseId,
                    cashRegisterId: $cashRegisterId,
                );

                return $resultDto->toArray();
            });
        } catch (SalesDocumentException $e) {
            throw $e;
        } catch (DomainException $e) {
            throw new SalesDocumentException($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            throw new SalesDocumentException($e->getMessage(), 422);
        }

        if ($this->taxBridgeService->supportsDocumentKind((string) ($result['document_kind'] ?? ''))
            && strtoupper((string) ($result['status'] ?? '')) === 'ISSUED') {
            $this->taxBridgeService->dispatchOnIssue(
                (int) $companyId,
                $result['branch_id'] !== null ? (int) $result['branch_id'] : null,
                (int) $result['id']
            );
        }

        return $result;
    }

    private function resolveIssueAtForStorage($issueAt)
    {
        if ($issueAt === null || $issueAt === '') {
            return now();
        }
        $text = trim((string) $issueAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $limaNow = now('America/Lima');
            return $text . ' ' . $limaNow->format('H:i:s') . '-05:00';
        }
        return $issueAt;
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')->where('company_id', $companyId)->first();
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
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
            'enforce_lot_for_tracked' => (bool) $row->enforce_lot_for_tracked,
        ];
    }

    private function isCommerceFeatureEnabledForContext(int $companyId, ?int $branchId, string $featureCode): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, $featureCode, false);
    }

    private function isCommerceFeatureEnabledForContextWithDefault(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled): bool
    {
        return $this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, $featureCode, $defaultEnabled);
    }

    private function isSellerActor(string $roleProfile, string $roleCode): bool
    {
        return $this->support->isSellerActor($roleProfile, $roleCode);
    }

    private function isCashierActor(string $roleProfile, string $roleCode): bool
    {
        return $this->support->isCashierActor($roleProfile, $roleCode);
    }

    private function isAdminActor(string $roleCode): bool
    {
        return $this->support->isAdminActor($roleCode);
    }

    private function resolveAuthRoleContext(int $userId, int $companyId): array
    {
        return $this->support->resolveAuthRoleContext($userId, $companyId);
    }

}
