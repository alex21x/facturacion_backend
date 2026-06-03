<?php

namespace App\Services\Sales\Documents;

use App\Application\DTOs\Sales\PaymentSummaryDTO;
use Illuminate\Support\Facades\DB;

class SalesDocumentPaymentMetadataService
{
    public function __construct(private SalesDocumentSupportService $support)
    {
    }

    public function summarizePayments(array $payments): PaymentSummaryDTO
    {
        $paidTotal = 0.0;
        $paymentTotal = 0.0;
        $pendingPayments = [];

        foreach ($payments as $payment) {
            $paymentAmount = (float) ($payment['amount'] ?? 0);
            $paymentTotal += $paymentAmount;

            if (($payment['status'] ?? 'PENDING') === 'PAID') {
                $paidTotal += $paymentAmount;
            } else {
                $pendingPayments[] = $payment;
            }
        }

        return new PaymentSummaryDTO($paidTotal, $paymentTotal, $pendingPayments);
    }

    public function assertIssuedDocumentPaymentConsistency(bool $isPreDocument, string $documentStatus, float $paymentTotal, float $grandTotal, array $pendingPayments): void
    {
        if ($isPreDocument || strtoupper($documentStatus) !== 'ISSUED') {
            return;
        }

        if ($paymentTotal <= 0) {
            throw new SalesDocumentException('Debe registrar pagos para el comprobante emitido.');
        }

        if (abs($paymentTotal - $grandTotal) > 0.01) {
            throw new SalesDocumentException('La suma de pagos no coincide con el total del comprobante.');
        }

        foreach ($pendingPayments as $pendingPayment) {
            $dueAt = trim((string) ($pendingPayment['due_at'] ?? ''));
            if ($dueAt === '') {
                throw new SalesDocumentException('Toda cuota pendiente debe incluir fecha de pago.');
            }
        }
    }

    public function enrichPaymentMetadata(array $metadata, array $pendingPayments, float $paidTotal, int $companyId, ?int $branchId, array $payments = []): array
    {
        $isCreditSale = count($pendingPayments) > 0;
        $metadata['payment_condition'] = $isCreditSale ? 'CREDITO' : 'CONTADO';
        $metadata['credit_installments'] = $isCreditSale
            ? array_values(array_map(function ($payment, $index) {
                return [
                    'installment_no' => $index + 1,
                    'amount' => round((float) ($payment['amount'] ?? 0), 2),
                    'due_at' => $payment['due_at'] ?? null,
                    'notes' => isset($payment['notes']) && trim((string) $payment['notes']) !== ''
                        ? trim((string) $payment['notes'])
                        : null,
                ];
            }, $pendingPayments, array_keys($pendingPayments)))
            : [];
        $metadata['credit_installments_count'] = $isCreditSale ? count($pendingPayments) : 0;
        $metadata['credit_total'] = $isCreditSale
            ? round(array_reduce($pendingPayments, function ($carry, $payment) {
                return $carry + (float) ($payment['amount'] ?? 0);
            }, 0.0), 2)
            : 0;

        $declaredAdvance = isset($metadata['advance_amount']) && is_numeric($metadata['advance_amount'])
            ? (float) $metadata['advance_amount']
            : 0.0;
        $declaredAdvance = max(0.0, min($declaredAdvance, $paidTotal));

        if ($declaredAdvance > 0.00001 && !$this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ANTICIPO_ENABLED', false)) {
            throw new SalesDocumentException('Anticipos no habilitados para esta empresa/sucursal.');
        }

        $metadata['advance_amount'] = round($declaredAdvance, 2);
        $metadata['has_advance'] = $declaredAdvance > 0.00001;

        $paymentMethodIds = collect($payments)
            ->map(fn ($payment) => isset($payment['payment_method_id']) ? (int) $payment['payment_method_id'] : 0)
            ->filter(fn ($methodId) => $methodId > 0)
            ->unique()
            ->values()
            ->all();

        $paymentMethodMap = [];
        if (!empty($paymentMethodIds)) {
            $paymentMethodMap = DB::table('master.payment_types')
                ->whereIn('id', $paymentMethodIds)
                ->pluck('name', 'id')
                ->map(fn ($name) => trim((string) $name))
                ->all();
        }

        $metadata['payment_breakdown'] = array_values(array_map(function ($payment) use ($paymentMethodMap) {
            $methodId = isset($payment['payment_method_id']) ? (int) $payment['payment_method_id'] : 0;
            $status = strtoupper(trim((string) ($payment['status'] ?? 'PENDING')));
            if (!in_array($status, ['PENDING', 'PAID', 'CANCELED'], true)) {
                $status = 'PENDING';
            }

            return [
                'payment_method_id' => $methodId > 0 ? $methodId : null,
                'payment_method_name' => $methodId > 0
                    ? ((string) ($paymentMethodMap[$methodId] ?? ('Metodo #' . $methodId)))
                    : null,
                'amount' => round((float) ($payment['amount'] ?? 0), 2),
                'status' => $status,
                'paid_at' => $payment['paid_at'] ?? null,
                'due_at' => $payment['due_at'] ?? null,
                'notes' => isset($payment['notes']) && trim((string) $payment['notes']) !== ''
                    ? trim((string) $payment['notes'])
                    : null,
            ];
        }, $payments));

        return $metadata;
    }
}
