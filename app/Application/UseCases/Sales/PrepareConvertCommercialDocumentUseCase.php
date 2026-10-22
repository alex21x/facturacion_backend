<?php

namespace App\Application\UseCases\Sales;

use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use App\Services\Sales\Documents\SalesDocumentConversionService;
use App\Services\Sales\Documents\SalesDocumentException;
use App\Services\Sales\SalesBusinessRuleService;
use App\Services\Sales\SalesLookupService;
use Carbon\Carbon;

class PrepareConvertCommercialDocumentUseCase
{
    private const FEATURE_ALLOW_RECEIPT_RUC = 'SALES_ALLOW_RECEIPT_WITH_RUC';

    public function __construct(
        private SalesDocumentConversionService $salesDocumentConversionService,
        private SalesBusinessRuleService $salesBusinessRuleService,
        private SalesLookupService $salesLookupService
    ) {
    }

    public function execute(object $source, array $payload, int $companyId, int $sourceId, bool $sellerToCashierEnabled): array
    {
        if (in_array((string) $source->status, ['VOID', 'CANCELED'], true)) {
            throw new SalesDocumentException('No se puede convertir un documento anulado/cancelado');
        }

        $targetDocumentKind = (string) $payload['target_document_kind'];
        $targetStatus = isset($payload['status']) && $payload['status'] !== null
            ? (string) $payload['status']
            : 'ISSUED';

        if ($targetDocumentKind === 'SALES_ORDER' && strtoupper($targetStatus) !== 'ISSUED') {
            $targetStatus = 'ISSUED';
        }

        if ((string) $source->document_kind === 'SALES_ORDER' && $targetDocumentKind === 'SALES_ORDER') {
            throw new SalesDocumentException('El documento origen ya es una nota de pedido');
        }

        $alreadyConverted = $this->salesDocumentConversionService->alreadyConvertedToTarget(
            $companyId,
            $sourceId,
            $targetDocumentKind
        );

        if ($alreadyConverted) {
            throw new SalesDocumentException('El documento ya fue convertido a ' . $targetDocumentKind, 409);
        }

        $sourceItems = $this->salesDocumentConversionService->getSourceItems($sourceId);

        if ($sourceItems->isEmpty()) {
            throw new SalesDocumentException('El documento origen no tiene items para convertir');
        }

        $sourceItemIds = $sourceItems->pluck('id')->map(function ($rowId) {
            return (int) $rowId;
        })->values()->all();

        $lotsByItem = $this->salesDocumentConversionService->getLotsGroupedByItemIds($sourceItemIds);

        $series = isset($payload['series']) && trim((string) $payload['series']) !== ''
            ? trim((string) $payload['series'])
            : null;

        if ($series === null) {
            $targetDocumentKindId = $this->salesLookupService->resolveDocumentKindIdByCode($targetDocumentKind) ?? 0;
            $targetDocumentKindCode = $this->salesLookupService->resolveCanonicalDocumentKindCode($targetDocumentKind, $targetDocumentKindId > 0 ? $targetDocumentKindId : null)
                ?? strtoupper(trim((string) $targetDocumentKind));

            $candidateSeries = $this->salesDocumentConversionService->findCandidateSeries(
                $companyId,
                $targetDocumentKindCode,
                $targetDocumentKindId,
                $source->branch_id !== null ? (int) $source->branch_id : null,
                $source->warehouse_id !== null ? (int) $source->warehouse_id : null
            );

            if (!$candidateSeries) {
                throw new SalesDocumentException('No existe serie habilitada para ' . $targetDocumentKind);
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
        $sourcePaidPayments = strtoupper((string) $source->document_kind) === 'SALES_ORDER'
            ? $this->salesDocumentConversionService->getPaidPaymentsForSource($sourceId)
            : [];

        $sourceHadStockImpact = CommercialDocumentPolicy::shouldAffectStock((string) $source->document_kind, (string) $source->status);

        // Total the converted document will have once the global discount (if flag active) is applied over the items.
        $sourceBranchId = $source->branch_id !== null ? (int) $source->branch_id : null;
        $itemDiscountEnabled = $this->isFeatureEnabledForBranch($companyId, $sourceBranchId, 'SALES_ITEM_DISCOUNT_ENABLED');
        $sourceItemsTotal = (float) $sourceItems->sum(function ($item) use ($itemDiscountEnabled) {
            $lineTotal = (float) $item->total;
            if ($itemDiscountEnabled || (float) $item->discount_total <= 0) {
                return $lineTotal;
            }
            $meta = is_string($item->metadata ?? null) ? json_decode($item->metadata, true) : (array) ($item->metadata ?? []);
            if (filter_var($meta['is_free_operation'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $lineTotal;
            }

            return (float) $item->subtotal + (float) $item->tax_total;
        });
        $sourceGlobalDiscount = CommercialDocumentPolicy::resolveGlobalDiscount(
            $sourceMetadata,
            $this->isFeatureEnabledForBranch($companyId, $sourceBranchId, 'SALES_GLOBAL_DISCOUNT_ENABLED'),
            round($sourceItemsTotal, 2)
        );
        $expectedConvertedTotal = round(max($sourceItemsTotal - $sourceGlobalDiscount, 0.0), 2);

        $allProductIds = $sourceItems
            ->pluck('product_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $validProductMap = [];
        if (!empty($allProductIds)) {
            $validProductMap = $this->salesDocumentConversionService->getValidProductIdMap($companyId, $allProductIds);
        }

        $sourceNumber = (string) $source->series . '-' . (string) $source->number;
        $originSellerUserId = 0;
        if (isset($sourceMetadata['origin_seller_user_id']) && is_numeric($sourceMetadata['origin_seller_user_id'])) {
            $originSellerUserId = (int) $sourceMetadata['origin_seller_user_id'];
        }
        if ($originSellerUserId <= 0) {
            $originSellerUserId = (int) ($source->created_by ?? 0);
        }

        $originSellerUserName = trim((string) ($sourceMetadata['origin_seller_user_name'] ?? ''));
        if ($originSellerUserName === '' && $originSellerUserId > 0) {
            $originSellerUserName = $this->salesDocumentConversionService->resolveUserFullName($originSellerUserId);
        }
        $resolvedPaymentMethodId = isset($payload['payment_method_id'])
            ? (int) $payload['payment_method_id']
            : ($source->payment_method_id !== null ? (int) $source->payment_method_id : null);

        $requestedPayments = collect($payload['payments'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) {
                $methodId = isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : 0;
                $amount = isset($row['amount']) ? round((float) $row['amount'], 2) : 0.0;
                $status = strtoupper(trim((string) ($row['status'] ?? 'PAID')));
                if (!in_array($status, ['PENDING', 'PAID', 'CANCELED'], true)) {
                    $status = 'PAID';
                }

                return [
                    'payment_method_id' => $methodId,
                    'amount' => $amount,
                    'status' => $status,
                    'paid_at' => $row['paid_at'] ?? null,
                    'due_at' => $row['due_at'] ?? null,
                    'notes' => array_key_exists('notes', $row) ? $row['notes'] : null,
                ];
            })
            ->filter(fn (array $row) => $row['payment_method_id'] > 0 && $row['amount'] > 0)
            ->values();

        $shouldUseRequestedPayments = strtoupper($targetStatus) === 'ISSUED'
            && $requestedPayments->isNotEmpty();

        if ($shouldUseRequestedPayments) {
            $requestedPaymentTotal = (float) $requestedPayments
                ->filter(fn (array $row) => $row['status'] !== 'CANCELED')
                ->sum('amount');

            if (abs($requestedPaymentTotal - $expectedConvertedTotal) > 0.01) {
                throw new SalesDocumentException('La suma de pagos debe coincidir con el total del documento convertido.');
            }

            $resolvedPaymentMethodId = (int) ($requestedPayments->first()['payment_method_id'] ?? $resolvedPaymentMethodId);
        }

        if ($sellerToCashierEnabled && $targetDocumentKind === 'SALES_ORDER' && ($resolvedPaymentMethodId === null || $resolvedPaymentMethodId <= 0)) {
            $resolvedPaymentMethodId = $this->salesLookupService->resolveFallbackPaymentMethodId($companyId);
        }

        $sourceCustomerIdentity = $this->salesLookupService->fetchCustomerIdentityForSalesValidation($companyId, (int) $source->customer_id);
        if ($this->salesBusinessRuleService->documentKindRequiresRucCustomer($targetDocumentKind)) {
            if (!$sourceCustomerIdentity || !$this->salesBusinessRuleService->customerHasRucIdentity($sourceCustomerIdentity)) {
                throw new SalesDocumentException('Para convertir a este tipo de documento el cliente debe tener RUC valido (11 digitos).');
            }
        }

        $allowReceiptRuc = $this->isCompanyFeatureEnabled($companyId, self::FEATURE_ALLOW_RECEIPT_RUC, false);
        if (
            $this->salesBusinessRuleService->documentKindDisallowsRucCustomer($targetDocumentKind, $allowReceiptRuc)
            && $sourceCustomerIdentity
            && $this->salesBusinessRuleService->customerHasRucIdentity($sourceCustomerIdentity)
        ) {
            throw new SalesDocumentException('Para convertir a boleta no se permite cliente con RUC. Active el flag de empresa si desea habilitar boleta con RUC.');
        }

        $itemsPayload = $sourceItems->map(function ($item) use ($lotsByItem, $validProductMap) {
            $itemLots = $lotsByItem->get((int) $item->id, collect())->map(function ($lot) {
                return [
                    'lot_id' => (int) $lot->lot_id,
                    'qty' => (float) $lot->qty,
                ];
            })->values()->all();

            $productId = $item->product_id !== null ? (int) $item->product_id : null;
            if ($productId !== null && !isset($validProductMap[$productId])) {
                $productId = null;
            }

            return [
                'line_no' => (int) $item->line_no,
                'product_id' => $productId,
                'unit_id' => $item->unit_id !== null ? (int) $item->unit_id : null,
                'price_tier_id' => $item->price_tier_id !== null ? (int) $item->price_tier_id : null,
                'tax_category_id' => $item->tax_category_id !== null ? (int) $item->tax_category_id : null,
                'description' => (string) $item->description,
                'qty' => (float) $item->qty,
                'qty_base' => (float) $item->qty_base,
                'conversion_factor' => (float) $item->conversion_factor,
                'base_unit_price' => (float) $item->base_unit_price,
                'unit_price' => (float) $item->unit_price,
                'unit_cost' => null,
                'wholesale_discount_percent' => (float) $item->wholesale_discount_percent,
                'price_source' => $item->price_source ?: 'MANUAL',
                'discount_total' => (float) $item->discount_total,
                'tax_total' => (float) $item->tax_total,
                'subtotal' => (float) $item->subtotal,
                'total' => (float) $item->total,
                'metadata' => is_string($item->metadata ?? null) ? (json_decode($item->metadata, true) ?: null) : null,
                'lots' => !empty($itemLots) ? $itemLots : null,
            ];
        })->values()->all();

        $conversionMetadata = [
            'source_document_id' => $sourceId,
            'source_document_kind' => (string) $source->document_kind,
            'source_document_number' => $sourceNumber,
            'conversion_origin' => 'SALES_MODULE',
            'stock_already_discounted' => $sourceHadStockImpact,
            'defer_sunat_send' => filter_var($payload['defer_sunat_send'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        $conversionMetadata['origin_seller_user_id'] = $originSellerUserId > 0 ? $originSellerUserId : null;
        $conversionMetadata['origin_seller_user_name'] = $originSellerUserName !== '' ? $originSellerUserName : null;

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
            'issue_at' => $this->resolveIssueAtForStorage($payload['issue_at'] ?? null),
            'due_at' => $this->resolveDueAtForStorage($payload['due_at'] ?? $source->due_at),
            'customer_id' => (int) $source->customer_id,
            'currency_id' => (int) $source->currency_id,
            'payment_method_id' => $resolvedPaymentMethodId,
            'exchange_rate' => $source->exchange_rate !== null ? (float) $source->exchange_rate : null,
            'notes' => $payload['notes'] ?? $source->notes,
            // client_request_id identifies the source creation; inheriting it makes creation return the source as an idempotent retry.
            'metadata' => array_merge(array_diff_key($sourceMetadata, ['client_request_id' => true]), $conversionMetadata),
            'status' => $targetStatus,
            'items' => $itemsPayload,
            'payments' => $shouldUseRequestedPayments
                ? $requestedPayments->map(function (array $row) {
                    return [
                        'payment_method_id' => (int) $row['payment_method_id'],
                        'amount' => (float) $row['amount'],
                        'status' => $row['status'],
                        'paid_at' => $row['status'] === 'PAID'
                            ? ($row['paid_at'] ?? now('America/Lima')->format('Y-m-d H:i:sP'))
                            : null,
                        'due_at' => $row['due_at'] ?? null,
                        'notes' => $row['notes'],
                        'method' => 'REGISTERED',
                    ];
                })->values()->all()
                : ((
                    in_array($targetDocumentKind, ['INVOICE', 'RECEIPT', 'SALES_ORDER'], true)
                )
                    && strtoupper($targetStatus) === 'ISSUED'
                    && $resolvedPaymentMethodId !== null
                    ? $this->buildSourceConversionPayments(
                        $sourceMetadata,
                        strtoupper((string) $source->status),
                        $resolvedPaymentMethodId,
                        $expectedConvertedTotal,
                        $source->due_at ?? null,
                        $sourcePaidPayments
                    )
                    : []),
        ];

        return [
            'forward_payload' => $forwardPayload,
        ];
    }

    // Same resolution as document creation: branch override first, then company.
    private function isFeatureEnabledForBranch(int $companyId, ?int $branchId, string $featureCode): bool
    {
        $code = strtoupper(trim($featureCode));
        $find = fn ($rows) => $rows->first(fn ($t) => strtoupper(trim((string) ($t->feature_code ?? ''))) === $code);

        if ($branchId !== null) {
            $branchRow = $find($this->salesLookupService->loadBranchFeatureToggles($companyId, $branchId));
            if ($branchRow && $branchRow->is_enabled !== null) {
                return (bool) $branchRow->is_enabled;
            }
        }

        return $this->isCompanyFeatureEnabled($companyId, $featureCode, false);
    }

    private function buildSourceConversionPayments(
        array $sourceMetadata,
        string $sourceStatus,
        int $paymentMethodId,
        float $expectedTotal,
        $sourceDueAt,
        array $sourcePaidPayments = []
    ): array
    {
        $breakdown = collect($sourceMetadata['payment_breakdown'] ?? [])
            ->filter(fn ($row) => is_array($row) && (int) ($row['payment_method_id'] ?? 0) > 0 && (float) ($row['amount'] ?? 0) > 0)
            ->values();
        $isCredit = strtoupper(trim((string) ($sourceMetadata['payment_condition'] ?? ''))) === 'CREDITO';
        $isSourceIssued = $sourceStatus === 'ISSUED';

        if (!$isCredit) {
            $paidRows = $this->mapSourcePaidPayments($sourcePaidPayments);
            if ($paidRows === []) {
                $paidRows = $breakdown
                    ->filter(fn (array $row) => !$isSourceIssued || strtoupper(trim((string) ($row['status'] ?? 'PAID'))) === 'PAID')
                    ->map(fn (array $row) => [
                        'payment_method_id' => (int) $row['payment_method_id'],
                        'amount' => round((float) $row['amount'], 2),
                        'status' => 'PAID',
                        'paid_at' => now('America/Lima')->format('Y-m-d H:i:sP'),
                        'method' => 'REGISTERED',
                    ])
                    ->values()
                    ->all();
            }

            if ($paidRows === []) {
                $paidRows[] = [
                    'payment_method_id' => $paymentMethodId,
                    'amount' => $expectedTotal,
                    'status' => 'PAID',
                    'paid_at' => now('America/Lima')->format('Y-m-d H:i:sP'),
                    'method' => 'REGISTERED',
                ];
            }

            $paidTotal = round(array_sum(array_column($paidRows, 'amount')), 2);
            if ($paidTotal >= $expectedTotal) {
                $this->normalizePaymentRowsToTotal($paidRows, $expectedTotal);
                return $paidRows;
            }

            return [
                ...$paidRows,
                [
                    'payment_method_id' => $paymentMethodId,
                    'amount' => round($expectedTotal - $paidTotal, 2),
                    'due_at' => $sourceDueAt
                        ? Carbon::parse($sourceDueAt)->format('Y-m-d')
                        : now('America/Lima')->addDays(30)->format('Y-m-d'),
                    'status' => 'PENDING',
                    'method' => 'REGISTERED',
                ],
            ];
        }

        $installments = collect($sourceMetadata['credit_installments'] ?? [])
            ->filter(fn ($row) => is_array($row) && (float) ($row['amount'] ?? 0) > 0 && trim((string) ($row['due_at'] ?? '')) !== '')
            ->values();

        $declaredAdvance = max(0.0, (float) ($sourceMetadata['advance_amount'] ?? 0));
        $paidBreakdown = $this->mapSourcePaidPayments($sourcePaidPayments);
        if ($paidBreakdown === []) {
            $paidBreakdown = $breakdown
                ->filter(fn (array $row) => !$isSourceIssued || strtoupper(trim((string) ($row['status'] ?? ''))) === 'PAID')
                ->map(fn (array $row) => [
                    'payment_method_id' => (int) $row['payment_method_id'],
                    'amount' => round((float) $row['amount'], 2),
                    'status' => 'PAID',
                    'paid_at' => now('America/Lima')->format('Y-m-d H:i:sP'),
                    'method' => 'REGISTERED',
                ])
                ->values()
                ->all();
        }
        $paidSum = array_sum(array_column($paidBreakdown, 'amount'));

        if (!$isSourceIssued && $declaredAdvance > 0 && $paidSum > $declaredAdvance) {
            $this->normalizePaymentRowsToTotal($paidBreakdown, $declaredAdvance);
            $paidSum = array_sum(array_column($paidBreakdown, 'amount'));
        }

        if ($paidBreakdown === [] && $declaredAdvance > 0) {
            $paidBreakdown[] = [
                'payment_method_id' => $paymentMethodId,
                'amount' => round(min($declaredAdvance, $expectedTotal), 2),
                'status' => 'PAID',
                'paid_at' => now('America/Lima')->format('Y-m-d H:i:sP'),
                'method' => 'REGISTERED',
            ];
            $paidSum = (float) $paidBreakdown[0]['amount'];
        }

        $pendingTotal = round(max(0.0, $expectedTotal - $paidSum), 2);
        $pendingRows = $installments->map(fn ($row) => [
            'payment_method_id' => $paymentMethodId,
            'amount' => round((float) $row['amount'], 2),
            'due_at' => $row['due_at'],
            'status' => 'PENDING',
            'notes' => $row['notes'] ?? null,
            'method' => 'REGISTERED',
        ])->all();

        if ($pendingRows === [] && $pendingTotal > 0) {
            $dueAt = $sourceDueAt ? Carbon::parse($sourceDueAt)->format('Y-m-d') : now('America/Lima')->addDays(30)->format('Y-m-d');
            $pendingRows[] = [
                'payment_method_id' => $paymentMethodId,
                'amount' => $pendingTotal,
                'due_at' => $dueAt,
                'status' => 'PENDING',
                'method' => 'REGISTERED',
            ];
        } elseif ($pendingRows !== []) {
            $this->normalizePaymentRowsToTotal($pendingRows, $pendingTotal);
        }

        return [...$paidBreakdown, ...$pendingRows];
    }

    private function mapSourcePaidPayments(array $sourcePaidPayments): array
    {
        return collect($sourcePaidPayments)
            ->filter(fn (array $row) => (int) ($row['payment_method_id'] ?? 0) > 0 && (float) ($row['amount'] ?? 0) > 0)
            ->map(fn (array $row) => [
                'payment_method_id' => (int) $row['payment_method_id'],
                'amount' => round((float) $row['amount'], 2),
                'status' => 'PAID',
                'paid_at' => $row['paid_at'] ?? null,
                'notes' => $row['notes'] ?? null,
                'method' => 'REGISTERED',
            ])
            ->values()
            ->all();
    }

    private function normalizePaymentRowsToTotal(array &$rows, float $targetTotal): void
    {
        if ($rows === []) {
            return;
        }

        $sum = array_sum(array_column($rows, 'amount'));
        $diff = round($targetTotal - $sum, 2);
        $lastIndex = array_key_last($rows);
        $rows[$lastIndex]['amount'] = round((float) $rows[$lastIndex]['amount'] + $diff, 2);
        if ($rows[$lastIndex]['amount'] <= 0) {
            $rows[$lastIndex]['amount'] = round($targetTotal, 2);
            $rows = [$rows[$lastIndex]];
        }
    }

    private function isCompanyFeatureEnabled(int $companyId, string $featureCode, bool $defaultValue): bool
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        if ($normalizedFeatureCode === '') {
            return $defaultValue;
        }

        $row = $this->salesLookupService->loadCompanyFeatureToggles($companyId)
            ->first(function ($toggle) use ($normalizedFeatureCode) {
                return strtoupper(trim((string) ($toggle->feature_code ?? ''))) === $normalizedFeatureCode;
            });

        if (!$row) {
            return $defaultValue;
        }

        return (bool) ($row->is_enabled ?? false);
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
            return Carbon::parse($text)->setTimezone('America/Lima')->format('Y-m-d H:i:sP');
        } catch (\Throwable $e) {
            return $issueAt;
        }
    }

    private function resolveDueAtForStorage($dueAt)
    {
        if ($dueAt === null || $dueAt === '') {
            return null;
        }

        $text = trim((string) $dueAt);
        if ($text === '' || in_array(strtolower($text), ['invalid date', 'undefined', 'null', 'nan'], true)) {
            return null;
        }

        $normalized = str_replace(',', '', $text);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
            return $normalized . ' 00:00:00';
        }

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $normalized);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        try {
            return Carbon::parse($normalized)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}