<?php

namespace App\Services\Sales\Documents;

use App\Application\DTOs\Sales\CreateCommercialDocumentResultDTO;
use App\Application\Commands\Sales\CreateCommercialDocumentCommand;
use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Services\Sales\TaxBridge\DailySummaryService;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesDocumentCreationService
{
    public function __construct(
        private TaxBridgeService $taxBridgeService,
        private DailySummaryService $dailySummaryService,
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
        if (is_array($payload['items'] ?? null)) {
            $payload['items'] = array_values(array_filter(array_map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                if (!array_key_exists('qty', $item) && array_key_exists('quantity', $item)) {
                    $item['qty'] = $item['quantity'];
                }

                return $item;
            }, $payload['items']), fn ($item) => is_array($item)));
        }
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
                $normalizedDocumentKind = $this->normalizeDocumentKind((string) $payload['document_kind']);
                $stockDirection = CommercialDocumentPolicy::stockDirectionForDocument((string) $payload['document_kind']);
                $stockAlreadyDiscounted = false;

                if (array_key_exists('stock_already_discounted', $metadata)) {
                    $stockAlreadyDiscounted = filter_var($metadata['stock_already_discounted'], FILTER_VALIDATE_BOOLEAN);
                }

                $isTributaryIssued = strtoupper((string) $documentStatus) === 'ISSUED'
                    && in_array($normalizedDocumentKind, ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'], true);

                $affectsStock = !$stockAlreadyDiscounted && CommercialDocumentPolicy::shouldAffectStock((string) $payload['document_kind'], (string) $documentStatus);
                if ($isTributaryIssued && !$stockAlreadyDiscounted) {
                    // Tributary documents are settled to inventory when SUNAT accepts them.
                    $affectsStock = false;
                }
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

                if ($isTributaryIssued) {
                    if ($stockAlreadyDiscounted) {
                        $metadata['inventory_pending_sunat'] = false;
                        $metadata['inventory_sunat_settled'] = true;
                    } else {
                        $metadata['inventory_pending_sunat'] = true;
                        $metadata['inventory_sunat_settled'] = false;
                    }
                    $metadata['stock_already_discounted'] = $stockAlreadyDiscounted;
                } else {
                    $metadata['inventory_pending_sunat'] = false;
                    $metadata['inventory_sunat_settled'] = $affectsStock;
                    if ($affectsStock) {
                        $metadata['stock_already_discounted'] = true;
                    }
                }

                $payload['metadata'] = $metadata;

                $resolvedIssueAt = $this->resolveIssueAtForStorage($payload['issue_at'] ?? null);
                $resolvedDocumentKindId = $this->resolveDocumentKindId((string) $payload['document_kind']);

                $documentId = $this->documentRepository->create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouseId,
                    'document_kind' => $payload['document_kind'],
                    'document_kind_id' => $resolvedDocumentKindId,
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
                    'metadata' => array_merge($payload['metadata'] ?? [], [
                        'cash_register_id' => $cashRegisterId !== null ? (int) $cashRegisterId : null,
                    ]),
                    'seller_user_id' => $authUser->id,
                    'created_by' => $authUser->id,
                    'updated_by' => $authUser->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->syncRestaurantTableOnSalesOrderCreate(
                    $companyId,
                    $branchId,
                    (string) $payload['document_kind'],
                    (string) $documentStatus,
                    is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []
                );

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

        $documentKind = $this->normalizeDocumentKind((string) ($result['document_kind'] ?? ''));
        $documentStatus = strtoupper((string) ($result['status'] ?? ''));
        $receiptSendMode = strtoupper(trim((string) (($payload['metadata']['receipt_send_mode'] ?? 'DIRECT'))));
        if (!in_array($receiptSendMode, ['DIRECT', 'SUMMARY'], true)) {
            $receiptSendMode = 'DIRECT';
        }
        $deferSunatSend = filter_var($payload['metadata']['defer_sunat_send'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($this->taxBridgeService->supportsDocumentKind(
            (string) ($result['document_kind'] ?? ''),
            isset($payload['document_kind_id']) && $payload['document_kind_id'] !== null ? (int) $payload['document_kind_id'] : null
        )
            && $documentStatus === 'ISSUED') {
            if ($deferSunatSend) {
                $this->taxBridgeService->deferDispatch(
                    (int) $companyId,
                    (int) $result['id']
                );
            } elseif ($documentKind === 'RECEIPT' && $receiptSendMode === 'SUMMARY') {
                $issueDate = $this->resolveIssueDateForSummary($payload['issue_at'] ?? null);

                $this->dailySummaryService->appendDocumentToOpenSummary(
                    (int) $companyId,
                    DailySummaryService::TYPE_DECLARATION,
                    (int) $result['id'],
                    (int) $authUser->id,
                    $result['branch_id'] !== null ? (int) $result['branch_id'] : null,
                    $issueDate
                );
            } else {
                $this->taxBridgeService->dispatchOnIssue(
                    (int) $companyId,
                    $result['branch_id'] !== null ? (int) $result['branch_id'] : null,
                    (int) $result['id']
                );
            }
        }

        return $result;
    }

    private function syncRestaurantTableOnSalesOrderCreate(
        int $companyId,
        ?int $branchId,
        string $documentKind,
        string $documentStatus,
        array $metadata
    ): void {
        if (strtoupper($documentKind) !== 'SALES_ORDER') {
            return;
        }

        if (in_array(strtoupper($documentStatus), ['VOID', 'CANCELED'], true)) {
            return;
        }

        $tableLabel = trim((string) ($metadata['table_label'] ?? ''));
        if ($tableLabel === '' || !$this->restaurantTablesStorageExists()) {
            return;
        }

        $table = DB::table('restaurant.tables')
            ->where('company_id', $companyId)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->where(function ($query) use ($tableLabel) {
                $query->whereRaw('UPPER(name) = ?', [mb_strtoupper($tableLabel)])
                    ->orWhereRaw('UPPER(code) = ?', [mb_strtoupper($tableLabel)]);
            })
            ->first(['id', 'status']);

        $currentStatus = $table ? strtoupper((string) $table->status) : '';

        if (!$table || in_array($currentStatus, ['DISABLED', 'RESERVED'], true)) {
            return;
        }

        DB::table('restaurant.tables')
            ->where('id', (int) $table->id)
            ->where('company_id', $companyId)
            ->update([
                'status' => 'OCCUPIED',
                'updated_at' => now(),
            ]);
    }

    private function restaurantTablesStorageExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'restaurant')
            ->where('table_name', 'tables')
            ->exists();
    }

    private function resolveIssueAtForStorage($issueAt)
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
            return \Carbon\Carbon::parse($text)->setTimezone('America/Lima')->format('Y-m-d H:i:sP');
        } catch (\Throwable $e) {
            return $issueAt;
        }
    }

    private function resolveIssueDateForSummary($issueAt): string
    {
        if ($issueAt === null || $issueAt === '') {
            return now('America/Lima')->toDateString();
        }

        $text = trim((string) $issueAt);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $matches) === 1) {
            return $matches[1];
        }

        try {
            return \Carbon\Carbon::parse($text, 'America/Lima')
                ->setTimezone('America/Lima')
                ->toDateString();
        } catch (\Throwable $e) {
            return now('America/Lima')->toDateString();
        }
    }

    private function resolveDocumentKindId(string $documentKind): ?int
    {
        $row = DB::table('sales.document_kinds')
            ->whereRaw('UPPER(TRIM(code)) = ?', [strtoupper(trim($documentKind))])
            ->select('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    private function normalizeDocumentKind(string $documentKind): string
    {
        $normalized = strtoupper(trim($documentKind));

        if ($normalized === 'CREDIT_NOTE' || str_starts_with($normalized, 'CREDIT_NOTE_')) {
            return 'CREDIT_NOTE';
        }

        if ($normalized === 'DEBIT_NOTE' || str_starts_with($normalized, 'DEBIT_NOTE_')) {
            return 'DEBIT_NOTE';
        }

        return $normalized;
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
