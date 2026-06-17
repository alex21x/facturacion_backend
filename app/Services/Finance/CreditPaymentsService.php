<?php

namespace App\Services\Finance;

use App\Domain\Finance\Repositories\CreditPaymentsRepositoryInterface;

class CreditPaymentsService
{
    public function __construct(private CreditPaymentsRepositoryInterface $repository)
    {
    }

    public function paginateCustomerCreditDocuments(int $companyId, ?int $branchId, int $page, int $perPage, ?string $search, ?string $paymentStatus): array
    {
        $result = $this->repository->paginateCustomerCreditDocuments($companyId, $branchId, $page, $perPage, $search, $paymentStatus);

        $rows = array_map(function ($row) {
            $total = (float) ($row->total_amount ?? 0);
            $paid = (float) ($row->paid_amount ?? 0);
            $balance = (float) ($row->balance_amount ?? 0);

            return [
                'id' => (int) $row->id,
                'document_kind' => (string) $row->document_kind,
                'document_kind_label' => $this->documentKindLabel((string) $row->document_kind),
                'series' => (string) $row->series,
                'number' => (int) $row->number,
                'issue_at' => (string) $row->issue_at,
                'document_status' => (string) ($row->document_status ?? ''),
                'counterpart_name' => (string) ($row->counterpart_name ?? '-'),
                'total_amount' => round($total, 2),
                'paid_amount' => round($paid, 2),
                'balance_amount' => round($balance, 2),
                'payment_status' => $balance <= 0.0001 ? 'CANCELED' : 'PENDING',
            ];
        }, $result['rows']->all());

        return $this->paginatePayload($rows, (int) $result['total'], $page, $perPage);
    }

    public function paginateSupplierCreditDocuments(int $companyId, ?int $branchId, int $page, int $perPage, ?string $search, ?string $paymentStatus): array
    {
        $result = $this->repository->paginateSupplierCreditDocuments($companyId, $branchId, $page, $perPage, $search, $paymentStatus);

        $rows = array_map(function ($row) {
            $total = (float) ($row->total_amount ?? 0);
            $paid = (float) ($row->paid_amount ?? 0);
            $balance = (float) ($row->balance_amount ?? 0);
            $status = $this->resolveSupplierPaymentStatus($total, $paid, $balance);

            return [
                'id' => (int) $row->id,
                'document_kind' => (string) $row->document_kind,
                'document_kind_label' => $this->documentKindLabel((string) $row->document_kind),
                'series' => (string) $row->series,
                'number' => (int) $row->number,
                'issue_at' => (string) $row->issue_at,
                'document_status' => (string) ($row->document_status ?? ''),
                'counterpart_name' => (string) ($row->counterpart_name ?? '-'),
                'total_amount' => round($total, 2),
                'paid_amount' => round($paid, 2),
                'balance_amount' => round($balance, 2),
                'payment_status' => $status,
            ];
        }, $result['rows']->all());

        return $this->paginatePayload($rows, (int) $result['total'], $page, $perPage);
    }

    public function listCustomerDocumentPayments(int $companyId, int $documentId): array
    {
        $doc = $this->repository->findCustomerDocument($companyId, $documentId);
        if (!$doc) {
            throw new \RuntimeException('Documento no encontrado', 404);
        }

        $payments = $this->normalizePayments($this->repository->listCustomerDocumentPayments($companyId, $documentId));
        $summary = $this->buildSummaryFromDocument((float) ($doc->total ?? 0), (float) ($doc->paid_total ?? 0), (float) ($doc->balance_due ?? 0));

        return [
            'document' => $this->normalizeCustomerDocument($doc),
            'summary' => $summary,
            'payments' => $payments,
        ];
    }

    public function createCustomerPayment(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        $doc = $this->repository->findCustomerDocument($companyId, $documentId);
        if (!$doc) {
            throw new \RuntimeException('Documento no encontrado', 404);
        }

        $insertedId = $this->repository->createCustomerDocumentPayment(
            $documentId,
            $this->normalizePaymentPayload($payload, (int) $authUser->id, true, isset($doc->payment_method_id) ? (int) $doc->payment_method_id : null)
        );
        $this->repository->recalculateCustomerDocumentTotals($documentId);

        return $this->listCustomerDocumentPayments($companyId, $documentId) + ['inserted_id' => $insertedId];
    }

    public function updateCustomerPayment(object $authUser, int $companyId, int $documentId, int $paymentId, array $payload): array
    {
        $doc = $this->repository->findCustomerDocument($companyId, $documentId);
        if (!$doc) {
            throw new \RuntimeException('Documento no encontrado', 404);
        }

        $updated = $this->repository->updateCustomerDocumentPayment(
            $companyId,
            $documentId,
            $paymentId,
            $this->normalizePaymentPayload($payload, (int) $authUser->id, false, isset($doc->payment_method_id) ? (int) $doc->payment_method_id : null)
        );
        if (!$updated) {
            throw new \RuntimeException('Pago no encontrado', 404);
        }

        $this->repository->recalculateCustomerDocumentTotals($documentId);
        return $this->listCustomerDocumentPayments($companyId, $documentId);
    }

    public function deleteCustomerPayment(int $companyId, int $documentId, int $paymentId): array
    {
        $deleted = $this->repository->deleteCustomerDocumentPayment($companyId, $documentId, $paymentId);
        if (!$deleted) {
            throw new \RuntimeException('Pago no encontrado', 404);
        }

        $this->repository->recalculateCustomerDocumentTotals($documentId);
        return $this->listCustomerDocumentPayments($companyId, $documentId);
    }

    public function listSupplierDocumentPayments(int $companyId, int $documentId): array
    {
        $doc = $this->repository->findSupplierDocument($companyId, $documentId);
        if (!$doc) {
            throw new \RuntimeException('Comprobante no encontrado', 404);
        }

        $payments = $this->normalizePayments($this->repository->listSupplierDocumentPayments($companyId, $documentId));
        $summary = $this->buildSupplierSummaryFromDocument((float) ($doc->total_amount ?? 0), (float) ($doc->paid_amount ?? 0), (float) ($doc->balance_amount ?? 0));
        $paymentsModuleEnabled = $this->repository->hasSupplierPaymentsModule();

        return [
            'document' => $this->normalizeSupplierDocument($doc),
            'summary' => $summary,
            'payments' => $payments,
            'payments_module_enabled' => $paymentsModuleEnabled,
            'payments_module_message' => $paymentsModuleEnabled
                ? null
                : 'Tu base actual no tiene la tabla inventory.stock_entry_payments. Se puede listar el saldo, pero no registrar pagos por detalle hasta crear esa tabla.',
        ];
    }

    public function createSupplierPayment(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        $doc = $this->repository->findSupplierDocument($companyId, $documentId);
        if (!$doc) {
            throw new \RuntimeException('Comprobante no encontrado', 404);
        }

        $this->repository->createSupplierDocumentPayment(
            $documentId,
            $this->normalizePaymentPayload($payload, (int) $authUser->id, true, isset($doc->payment_method_id) ? (int) $doc->payment_method_id : null)
        );
        return $this->listSupplierDocumentPayments($companyId, $documentId);
    }

    public function updateSupplierPayment(object $authUser, int $companyId, int $documentId, int $paymentId, array $payload): array
    {
        $doc = $this->repository->findSupplierDocument($companyId, $documentId);
        if (!$doc) {
            throw new \RuntimeException('Comprobante no encontrado', 404);
        }

        $updated = $this->repository->updateSupplierDocumentPayment(
            $companyId,
            $documentId,
            $paymentId,
            $this->normalizePaymentPayload($payload, (int) $authUser->id, false, isset($doc->payment_method_id) ? (int) $doc->payment_method_id : null)
        );
        if (!$updated) {
            throw new \RuntimeException('Pago no encontrado', 404);
        }

        return $this->listSupplierDocumentPayments($companyId, $documentId);
    }

    public function deleteSupplierPayment(int $companyId, int $documentId, int $paymentId): array
    {
        $deleted = $this->repository->deleteSupplierDocumentPayment($companyId, $documentId, $paymentId);
        if (!$deleted) {
            throw new \RuntimeException('Pago no encontrado', 404);
        }

        return $this->listSupplierDocumentPayments($companyId, $documentId);
    }

    public function paymentTicketHtml(string $title, array $document, array $payment): string
    {
        $amount = number_format((float) ($payment['amount'] ?? 0), 2, '.', '');
        $issuedAt = htmlspecialchars((string) ($payment['paid_at'] ?? $payment['due_at'] ?? $payment['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars((string) ($payment['status'] ?? ''), ENT_QUOTES, 'UTF-8');
        $counterpart = htmlspecialchars((string) ($document['counterpart_name'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $docLabel = htmlspecialchars((string) ($document['document_kind'] ?? '-') . ' ' . (string) ($document['series'] ?? '-') . '-' . (string) ($document['number'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $method = htmlspecialchars((string) ($payment['payment_method_name'] ?? '-'), ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="UTF-8"><title>Ticket de Pago</title>'
            . '<style>body{font-family:Arial,sans-serif;font-size:12px;margin:0;padding:10px;}h1{font-size:14px;margin:0 0 8px;}table{width:100%;border-collapse:collapse;}td{padding:2px 0;vertical-align:top;} .value{text-align:right;font-weight:600;} .line{margin:8px 0;border-top:1px dashed #333;} </style>'
            . '</head><body>'
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<table>'
            . '<tr><td>Comprobante</td><td class="value">' . $docLabel . '</td></tr>'
            . '<tr><td>Contraparte</td><td class="value">' . $counterpart . '</td></tr>'
            . '<tr><td>Metodo</td><td class="value">' . $method . '</td></tr>'
            . '<tr><td>Fecha</td><td class="value">' . $issuedAt . '</td></tr>'
            . '<tr><td>Estado</td><td class="value">' . $status . '</td></tr>'
            . '<tr><td>Monto</td><td class="value">S/ ' . $amount . '</td></tr>'
            . '</table>'
            . '<div class="line"></div><div style="text-align:center">Emitido por el sistema</div>'
            . '</body></html>';
    }

    private function normalizePayments(array $rows): array
    {
        return array_map(function (array $row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'payment_method_id' => isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : null,
                'payment_method_name' => (string) ($row['payment_method_name'] ?? '-'),
                'amount' => round((float) ($row['amount'] ?? 0), 2),
                'status' => strtoupper(trim((string) ($row['status'] ?? 'PENDING'))),
                'paid_at' => $row['paid_at'] ?? null,
                'due_at' => $row['due_at'] ?? null,
                'notes' => $row['notes'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows);
    }

    private function normalizePaymentPayload(array $payload, int $userId, bool $isCreate, ?int $defaultPaymentMethodId = null): array
    {
        $status = strtoupper(trim((string) ($payload['status'] ?? 'PAID')));
        if (!in_array($status, ['PENDING', 'PAID', 'CANCELED'], true)) {
            $status = 'PAID';
        }

        $paymentMethodId = isset($payload['payment_method_id'])
            ? (int) $payload['payment_method_id']
            : ((int) ($defaultPaymentMethodId ?? 0));

        if ($paymentMethodId <= 0) {
            throw new \RuntimeException('Debe indicar un metodo de pago valido.', 422);
        }

        $result = [
            'payment_method_id' => $paymentMethodId,
            'amount' => round((float) ($payload['amount'] ?? 0), 2),
            'status' => $status,
            'paid_at' => !empty($payload['paid_at']) ? $payload['paid_at'] : null,
            'due_at' => !empty($payload['due_at']) ? $payload['due_at'] : null,
            'notes' => isset($payload['notes']) ? trim((string) $payload['notes']) : null,
            'updated_by' => $userId,
            'updated_at' => now(),
        ];

        if ($isCreate) {
            $result['created_by'] = $userId;
            $result['created_at'] = now();
        }

        return $result;
    }

    private function normalizeCustomerDocument(object $doc): array
    {
        return [
            'id' => (int) $doc->id,
            'document_kind' => (string) $doc->document_kind,
            'document_kind_label' => $this->documentKindLabel((string) $doc->document_kind),
            'series' => (string) $doc->series,
            'number' => (int) $doc->number,
            'counterpart_name' => (string) ($doc->counterpart_name ?? '-'),
            'total_amount' => round((float) ($doc->total ?? 0), 2),
            'paid_amount' => round((float) ($doc->paid_total ?? 0), 2),
            'balance_amount' => round((float) ($doc->balance_due ?? 0), 2),
            'payment_status' => (float) ($doc->balance_due ?? 0) <= 0.0001 ? 'CANCELED' : 'PENDING',
        ];
    }

    private function normalizeSupplierDocument(object $doc): array
    {
        $total = (float) ($doc->total_amount ?? 0);
        $paid = (float) ($doc->paid_amount ?? 0);
        $balance = (float) ($doc->balance_amount ?? 0);

        return [
            'id' => (int) $doc->id,
            'document_kind' => (string) $doc->document_kind,
            'document_kind_label' => $this->documentKindLabel((string) $doc->document_kind),
            'series' => (string) $doc->series,
            'number' => (int) $doc->number,
            'counterpart_name' => (string) ($doc->counterpart_name ?? '-'),
            'total_amount' => round($total, 2),
            'paid_amount' => round($paid, 2),
            'balance_amount' => round($balance, 2),
            'payment_status' => $this->resolveSupplierPaymentStatus($total, $paid, $balance),
        ];
    }

    private function buildSupplierSummaryFromDocument(float $total, float $paid, float $balance): array
    {
        return [
            'total_amount' => round($total, 2),
            'paid_amount' => round($paid, 2),
            'balance_amount' => round($balance, 2),
            'payment_status' => $this->resolveSupplierPaymentStatus($total, $paid, $balance),
        ];
    }

    private function resolveSupplierPaymentStatus(float $total, float $paid, float $balance): string
    {
        // Same practical rule as sales collections: without real paid amount,
        // the document should remain pending even if legacy totals are zero.
        if ($paid <= 0.0001) {
            return 'PENDING';
        }

        if ($total > 0.0001 && $balance <= 0.0001) {
            return 'CANCELED';
        }

        return 'PENDING';
    }

    private function buildSummaryFromDocument(float $total, float $paid, float $balance): array
    {
        return [
            'total_amount' => round($total, 2),
            'paid_amount' => round($paid, 2),
            'balance_amount' => round($balance, 2),
            'payment_status' => $balance <= 0.0001 ? 'CANCELED' : 'PENDING',
        ];
    }

    private function paginatePayload(array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    private function documentKindLabel(string $kind): string
    {
        return match (strtoupper(trim($kind))) {
            'RECEIPT' => 'Boleta',
            'INVOICE' => 'Factura',
            'SALES_ORDER' => 'Pedido',
            'QUOTATION' => 'Cotizacion',
            'CREDIT_NOTE' => 'Nota de credito',
            'DEBIT_NOTE' => 'Nota de debito',
            'PURCHASE' => 'Compra',
            'PURCHASE_ORDER' => 'Orden de compra',
            default => $kind,
        };
    }
}
