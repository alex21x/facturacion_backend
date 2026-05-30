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

        $sourceHadStockImpact = CommercialDocumentPolicy::shouldAffectStock((string) $source->document_kind, (string) $source->status);

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

        if ($sellerToCashierEnabled && $targetDocumentKind === 'SALES_ORDER' && ($resolvedPaymentMethodId === null || $resolvedPaymentMethodId <= 0)) {
            $resolvedPaymentMethodId = $this->salesLookupService->resolveFallbackPaymentMethodId($companyId);
        }

        if ($this->salesBusinessRuleService->documentKindRequiresRucCustomer($targetDocumentKind)) {
            $sourceCustomerIdentity = $this->salesLookupService->fetchCustomerIdentityForSalesValidation($companyId, (int) $source->customer_id);
            if (!$sourceCustomerIdentity || !$this->salesBusinessRuleService->customerHasRucIdentity($sourceCustomerIdentity)) {
                throw new SalesDocumentException('Para convertir a este tipo de documento el cliente debe tener RUC valido (11 digitos).');
            }
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
            'metadata' => array_merge($sourceMetadata, $conversionMetadata),
            'status' => $targetStatus,
            'items' => $itemsPayload,
            'payments' => (
                in_array($targetDocumentKind, ['INVOICE', 'RECEIPT'], true)
                || ($sellerToCashierEnabled && $targetDocumentKind === 'SALES_ORDER')
            )
                && strtoupper($targetStatus) === 'ISSUED'
                && $resolvedPaymentMethodId !== null
                ? [[
                    'payment_method_id' => $resolvedPaymentMethodId,
                    'amount' => (float) $source->total,
                    'status' => 'PAID',
                    'paid_at' => now('America/Lima')->format('Y-m-d H:i:sP'),
                    'method' => 'REGISTERED',
                ]]
                : [],
        ];

        return [
            'forward_payload' => $forwardPayload,
        ];
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
