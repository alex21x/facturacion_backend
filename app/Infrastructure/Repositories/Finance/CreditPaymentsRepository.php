<?php

namespace App\Infrastructure\Repositories\Finance;

use App\Domain\Finance\Repositories\CreditPaymentsRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CreditPaymentsRepository implements CreditPaymentsRepositoryInterface
{
    private const DOCUMENT_MOVEMENT_REF_TYPES = ['INVOICE', 'RECEIPT', 'COMMERCIAL_DOCUMENT', 'SALES_ORDER'];
    private const EXCLUDED_DOCUMENT_STATUSES = ['CANCELED', 'VOID', 'VOIDED'];

    private ?bool $hasCommercialDocumentCustomerName = null;
    private ?bool $hasStockEntryPaymentsTable = null;
    private array $tableColumnsCache = [];

    public function paginateCustomerCreditDocuments(int $companyId, ?int $branchId, int $page, int $perPage, ?string $search = null, ?string $paymentStatus = null): array
    {
        $counterpartNameSelectExpr = $this->customerCounterpartNameExpr("'-'");
        $counterpartNameSearchExpr = $this->customerCounterpartNameExpr("''");
        $hasCustomerPaymentsTable = $this->hasTable('sales', 'commercial_document_payments');

        $paidExpr = 'COALESCE(d.paid_total, 0)';
        $balanceExpr = 'COALESCE(d.balance_due, 0)';
        $autoIssuedPaidExpr = $this->customerAutoIssuedPaidExpr();

        if ($hasCustomerPaymentsTable) {
            $paidExpr = 'GREATEST(COALESCE(cp.paid_amount, 0) - (' . $autoIssuedPaidExpr . '), 0)';
            $balanceExpr = 'GREATEST(COALESCE(d.total, 0) - (' . $paidExpr . '), 0)';
        }

        $base = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status as document_status',
                DB::raw($counterpartNameSelectExpr . ' as counterpart_name'),
                DB::raw('COALESCE(d.total, 0) as total_amount'),
                DB::raw($paidExpr . ' as paid_amount'),
                DB::raw($balanceExpr . ' as balance_amount'),
            ])
            ->where('d.company_id', $companyId)
            ->whereRaw("UPPER(COALESCE(d.status, '')) NOT IN ('VOID', 'CANCELED')")
            ->whereIn(DB::raw('UPPER(d.document_kind)'), ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'])
            ->where(function ($q) use ($balanceExpr, $paidExpr) {
                $q->whereRaw($balanceExpr . ' > 0.0001')
                    ->orWhereRaw($paidExpr . ' > 0.0001');
            });

        $this->applyCustomerCreditDocumentFilter($base);

        if ($hasCustomerPaymentsTable) {
            $customerPaymentSummary = DB::table('sales.commercial_document_payments')
                ->selectRaw('document_id, COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as paid_amount', ['PAID'])
                ->groupBy('document_id');

            $base->leftJoinSub($customerPaymentSummary, 'cp', function ($join) {
                $join->on('cp.document_id', '=', 'd.id');
            });
        }

        if ($branchId !== null) {
            $base->where(function ($q) use ($branchId) {
                $q->where('d.branch_id', $branchId)
                    ->orWhereNull('d.branch_id');
            });
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $base->where(function ($q) use ($term, $counterpartNameSearchExpr) {
                $q->whereRaw("CONCAT(COALESCE(d.series, ''), '-', COALESCE(d.number::text, '')) ILIKE ?", [$term])
                    ->orWhereRaw($counterpartNameSearchExpr . ' ILIKE ?', [$term]);
            });
        }

        if ($paymentStatus === 'PENDING') {
            $base->whereRaw($balanceExpr . ' > 0.0001');
        } elseif ($paymentStatus === 'CANCELED') {
            $base->whereRaw($balanceExpr . ' <= 0.0001');
        }

        $total = (clone $base)->count();
        $rows = $base
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return ['rows' => $rows, 'total' => (int) $total];
    }

    public function listCustomerDocumentPayments(int $companyId, int $documentId): array
    {
        return DB::table('sales.commercial_document_payments as p')
            ->join('sales.commercial_documents as d', 'd.id', '=', 'p.document_id')
            ->leftJoin('master.payment_types as pm_doc', 'pm_doc.id', '=', 'd.payment_method_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'p.payment_method_id')
            ->where('p.document_id', $documentId)
            ->whereRaw('NOT (' . $this->customerAutoIssuedPaidPredicateSql('p', 'd', 'pm_doc') . ')')
            ->whereExists(function ($q) use ($companyId) {
                $q->select(DB::raw('1'))
                    ->from('sales.commercial_documents as d')
                    ->whereColumn('d.id', 'p.document_id')
                    ->where('d.company_id', $companyId);
            })
            ->orderByDesc('p.id')
            ->get([
                'p.id',
                'p.document_id',
                'p.payment_method_id',
                'p.amount',
                'p.status',
                'p.paid_at',
                'p.due_at',
                'p.notes',
                'p.created_at',
                'pm.name as payment_method_name',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function createCustomerDocumentPayment(int $companyId, int $documentId, array $payload, ?int $cashRegisterId): int
    {
        return DB::transaction(function () use ($companyId, $documentId, $payload, $cashRegisterId): int {
            $document = $this->findLockedCustomerDocument($companyId, $documentId);
            if (!$document) {
                throw new \RuntimeException('Documento no encontrado', 404);
            }

            $this->assertCustomerPaymentFitsBalance($document, (float) ($payload['amount'] ?? 0), null, (string) ($payload['status'] ?? 'PAID'));
            $insertPayload = $this->filterPayloadByTableColumns(
                'sales',
                'commercial_document_payments',
                $payload + ['document_id' => $documentId]
            );
            $paymentId = (int) DB::table('sales.commercial_document_payments')->insertGetId($insertPayload);
            $sessionId = $this->syncCustomerPaymentCashMovement($companyId, $document, $paymentId, $payload, $cashRegisterId);
            $this->recalculateCustomerDocumentTotals($documentId);
            if ($sessionId !== null) {
                $this->recalculateCashSessionExpectedBalance($sessionId);
            }

            return $paymentId;
        });
    }

    public function updateCustomerDocumentPayment(int $companyId, int $documentId, int $paymentId, array $payload, ?int $cashRegisterId): bool
    {
        return DB::transaction(function () use ($companyId, $documentId, $paymentId, $payload, $cashRegisterId): bool {
            $document = $this->findLockedCustomerDocument($companyId, $documentId);
            if (!$document) {
                return false;
            }

            $existingPayment = DB::table('sales.commercial_document_payments')
                ->where('id', $paymentId)
                ->where('document_id', $documentId)
                ->lockForUpdate()
                ->first();
            if (!$existingPayment) {
                return false;
            }

            if (strtoupper((string) $existingPayment->status) === 'PAID' && !$this->customerPaymentHasCashMovement($companyId, $paymentId)) {
                throw new \RuntimeException('Este pago pertenece al registro original del comprobante y no se puede modificar desde Cobranzas.', 409);
            }

            $this->assertCustomerPaymentFitsBalance($document, (float) ($payload['amount'] ?? 0), $paymentId, (string) ($payload['status'] ?? 'PAID'));
            $updatePayload = $this->filterPayloadByTableColumns('sales', 'commercial_document_payments', $payload);
            if ($updatePayload === []) {
                return false;
            }

            DB::table('sales.commercial_document_payments')
                ->where('id', $paymentId)
                ->where('document_id', $documentId)
                ->update($updatePayload);

            $sessionId = $this->syncCustomerPaymentCashMovement($companyId, $document, $paymentId, $payload, $cashRegisterId);
            $this->recalculateCustomerDocumentTotals($documentId);
            if ($sessionId !== null) {
                $this->recalculateCashSessionExpectedBalance($sessionId);
            }

            return true;
        });
    }

    public function deleteCustomerDocumentPayment(int $companyId, int $documentId, int $paymentId, ?int $cashRegisterId): bool
    {
        return DB::transaction(function () use ($companyId, $documentId, $paymentId, $cashRegisterId): bool {
            if (!$this->findLockedCustomerDocument($companyId, $documentId)) {
                return false;
            }

            $payment = DB::table('sales.commercial_document_payments')
                ->where('id', $paymentId)
                ->where('document_id', $documentId)
                ->lockForUpdate()
                ->first();
            if (!$payment) {
                return false;
            }

            if (strtoupper((string) $payment->status) === 'PAID' && !$this->customerPaymentHasCashMovement($companyId, $paymentId)) {
                throw new \RuntimeException('Este pago pertenece al registro original del comprobante y no se puede eliminar desde Cobranzas.', 409);
            }

            $sessionId = $this->removeCustomerPaymentCashMovement($companyId, $paymentId, null, $cashRegisterId);
            DB::table('sales.commercial_document_payments')
                ->where('id', $paymentId)
                ->where('document_id', $documentId)
                ->delete();
            $this->recalculateCustomerDocumentTotals($documentId);
            if ($sessionId !== null) {
                $this->recalculateCashSessionExpectedBalance($sessionId);
            }

            return true;
        });
    }

    public function findCustomerDocument(int $companyId, int $documentId): ?object
    {
        $counterpartNameSelectExpr = $this->customerCounterpartNameExpr("'-'");

        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.company_id', $companyId)
            ->where('d.id', $documentId)
            ->whereIn(DB::raw('UPPER(d.document_kind)'), ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'])
            ->whereRaw("UPPER(COALESCE(d.status, '')) NOT IN ('CANCELED', 'VOID', 'VOIDED')")
            ->select([
                'd.id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.payment_method_id',
                'd.branch_id',
                'd.total',
                'd.paid_total',
                'd.balance_due',
                DB::raw($counterpartNameSelectExpr . ' as counterpart_name'),
            ]);
        $this->applyCustomerCreditDocumentFilter($query);

        return $query->first();
    }

    private function findLockedCustomerDocument(int $companyId, int $documentId): ?object
    {
        $query = DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.id', $documentId)
            ->whereIn(DB::raw('UPPER(d.document_kind)'), ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'])
            ->whereRaw("UPPER(COALESCE(d.status, '')) NOT IN ('CANCELED', 'VOID', 'VOIDED')");
        $this->applyCustomerCreditDocumentFilter($query);

        return $query
            ->lockForUpdate()
            ->first(['d.id', 'd.document_kind', 'd.branch_id', 'd.series', 'd.number', 'd.total']);
    }

    private function applyCustomerCreditDocumentFilter(Builder $query): void
    {
        $query->where(function (Builder $credit): void {
            $credit->whereExists(function (Builder $paymentMethod): void {
                $paymentMethod->select(DB::raw('1'))
                    ->from('master.payment_types as pm_credit')
                    ->whereColumn('pm_credit.id', 'd.payment_method_id')
                    ->whereRaw("UPPER(translate(COALESCE(pm_credit.name, ''), 'ÁÉÍÓÚáéíóú', 'AEIOUAEIOU')) LIKE '%CREDITO%'");
            })
                ->orWhereRaw("UPPER(COALESCE(d.metadata->>'payment_condition', '')) = 'CREDITO'")
                ->orWhereRaw("CASE WHEN COALESCE(d.metadata->>'credit_installments_count', '') ~ '^[0-9]+$' THEN (d.metadata->>'credit_installments_count')::int ELSE 0 END > 0")
                ->orWhereExists(function (Builder $sub): void {
                    $sub->select(DB::raw('1'))
                        ->from('sales.commercial_document_payments as pcred')
                        ->whereColumn('pcred.document_id', 'd.id')
                        ->where(function (Builder $payments): void {
                            $payments->whereNotNull('pcred.due_at')
                                ->orWhereRaw("UPPER(COALESCE(pcred.status, '')) = 'PENDING'");
                        });
                });
        });

        $query->whereNotExists(function (Builder $convertedDocument): void {
            $convertedDocument->select(DB::raw('1'))
                ->from('sales.commercial_documents as converted')
                ->whereColumn('converted.company_id', 'd.company_id')
                ->whereIn(DB::raw('UPPER(converted.document_kind)'), ['INVOICE', 'RECEIPT'])
                ->whereRaw("COALESCE(converted.metadata->>'source_document_id', '') = d.id::text")
                ->whereRaw("UPPER(COALESCE(converted.metadata->>'conversion_origin', '')) = 'SALES_MODULE'")
                ->whereRaw("UPPER(COALESCE(converted.status, '')) = 'ISSUED'");
        });
    }

    private function assertCustomerPaymentFitsBalance(object $document, float $amount, ?int $excludePaymentId, string $status): void
    {
        if (strtoupper(trim($status)) !== 'PAID') {
            return;
        }

        $paidQuery = DB::table('sales.commercial_document_payments as p')
            ->join('sales.commercial_documents as d', 'd.id', '=', 'p.document_id')
            ->leftJoin('master.payment_types as pm_doc', 'pm_doc.id', '=', 'd.payment_method_id')
            ->where('p.document_id', (int) $document->id)
            ->whereRaw("UPPER(COALESCE(p.status, '')) = 'PAID'")
            ->whereRaw('NOT (' . $this->customerAutoIssuedPaidPredicateSql('p', 'd', 'pm_doc') . ')');

        if ($excludePaymentId !== null) {
            $paidQuery->where('p.id', '<>', $excludePaymentId);
        }

        $alreadyPaid = (float) $paidQuery->sum('p.amount');
        $balance = max(0, round((float) ($document->total ?? 0) - $alreadyPaid, 2));
        if (round($amount, 2) > $balance + 0.0001) {
            throw new \RuntimeException('El monto del cobro supera el saldo pendiente del comprobante.', 422);
        }
    }

    private function syncCustomerPaymentCashMovement(int $companyId, object $document, int $paymentId, array $payment, ?int $cashRegisterId): ?int
    {
        $movement = DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('ref_type', 'CUSTOMER_CREDIT_PAYMENT')
            ->where('ref_id', $paymentId)
            ->lockForUpdate()
            ->first(['id', 'cash_session_id', 'cash_register_id']);

        if (strtoupper(trim((string) ($payment['status'] ?? 'PAID'))) !== 'PAID') {
            return $this->removeCustomerPaymentCashMovement($companyId, $paymentId, $movement, $cashRegisterId);
        }

        if ($movement) {
            $session = DB::table('sales.cash_sessions')
                ->where('company_id', $companyId)
                ->where('id', (int) $movement->cash_session_id)
                ->lockForUpdate()
                ->first(['id', 'status', 'cash_register_id']);
            if (!$session || strtoupper((string) $session->status) !== 'OPEN') {
                throw new \RuntimeException('No se puede modificar un cobro de una caja cerrada.', 409);
            }
            if ($cashRegisterId === null || $cashRegisterId !== (int) $session->cash_register_id) {
                throw new \RuntimeException('Selecciona la misma caja abierta donde se registró este cobro.', 409);
            }
            $sessionId = (int) $session->id;
        } else {
            if ($cashRegisterId === null || $cashRegisterId <= 0) {
                throw new \RuntimeException('Selecciona una caja abierta antes de registrar el cobro.', 409);
            }

            $sessionQuery = DB::table('sales.cash_sessions')
                ->where('company_id', $companyId)
                ->where('cash_register_id', $cashRegisterId)
                ->where('status', 'OPEN');
            if (!empty($document->branch_id)) {
                $sessionQuery->where(function ($query) use ($document): void {
                    $query->where('branch_id', (int) $document->branch_id)
                        ->orWhereNull('branch_id');
                });
            }

            $sessions = $sessionQuery->lockForUpdate()->limit(2)->get(['id', 'branch_id']);
            if ($sessions->count() !== 1) {
                if ($sessions->isEmpty()) {
                    throw new \RuntimeException('La caja seleccionada no está abierta para la sucursal del comprobante.', 409);
                }
                throw new \RuntimeException('Hay varias sesiones abiertas para la caja seleccionada. Contacta al administrador antes de registrar el cobro.', 409);
            }
            $session = $sessions->first();
            if (!$session) {
                throw new \RuntimeException('La caja seleccionada no está abierta para la sucursal del comprobante.', 409);
            }
            $sessionId = (int) $session->id;
        }

        $description = $this->customerCollectionDescription($document);
        $movementPayload = [
            'payment_method_id' => (int) $payment['payment_method_id'],
            'amount' => round((float) $payment['amount'], 2),
            'description' => $description,
            'notes' => $description,
            'movement_at' => $payment['paid_at'] ?? now(),
        ];

        if ($movement) {
            DB::table('sales.cash_movements')
                ->where('company_id', $companyId)
                ->where('id', (int) $movement->id)
                ->update($movementPayload);
        } else {
            $movementPayload += [
                'company_id' => $companyId,
                'branch_id' => $document->branch_id,
                'cash_register_id' => $cashRegisterId,
                'cash_session_id' => $sessionId,
                'movement_type' => 'INCOME',
                'ref_type' => 'CUSTOMER_CREDIT_PAYMENT',
                'ref_id' => $paymentId,
                'created_by' => $payment['created_by'] ?? $payment['updated_by'] ?? null,
                'user_id' => $payment['updated_by'] ?? $payment['created_by'] ?? null,
                'created_at' => now(),
            ];
            DB::table('sales.cash_movements')->insert($movementPayload);
        }

        return $sessionId;
    }

    private function customerPaymentHasCashMovement(int $companyId, int $paymentId): bool
    {
        return DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('ref_type', 'CUSTOMER_CREDIT_PAYMENT')
            ->where('ref_id', $paymentId)
            ->exists();
    }

    private function removeCustomerPaymentCashMovement(int $companyId, int $paymentId, ?object $movement = null, ?int $cashRegisterId = null): ?int
    {
        $movement ??= DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('ref_type', 'CUSTOMER_CREDIT_PAYMENT')
            ->where('ref_id', $paymentId)
            ->lockForUpdate()
            ->first(['id', 'cash_session_id', 'cash_register_id']);

        if (!$movement) {
            return null;
        }

        $session = DB::table('sales.cash_sessions')
            ->where('company_id', $companyId)
            ->where('id', (int) $movement->cash_session_id)
            ->lockForUpdate()
            ->first(['id', 'status']);
        if (!$session || strtoupper((string) $session->status) !== 'OPEN') {
            throw new \RuntimeException('No se puede anular un cobro de una caja cerrada.', 409);
        }
        if ($cashRegisterId === null || $cashRegisterId !== (int) $movement->cash_register_id) {
            throw new \RuntimeException('Selecciona la misma caja abierta donde se registró este cobro.', 409);
        }

        DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('id', (int) $movement->id)
            ->delete();

        return (int) $session->id;
    }

    private function recalculateCashSessionExpectedBalance(int $sessionId): void
    {
        $session = DB::table('sales.cash_sessions')
            ->where('id', $sessionId)
            ->lockForUpdate()
            ->first(['opening_balance']);
        if (!$session) {
            return;
        }

        $baseMovements = DB::table('sales.cash_movements as cm')
            ->leftJoin('sales.commercial_documents as cd', function ($join): void {
                $join->on('cd.id', '=', 'cm.ref_id')
                    ->whereIn('cm.ref_type', self::DOCUMENT_MOVEMENT_REF_TYPES);
            })
            ->where('cm.cash_session_id', $sessionId)
            ->where(function ($query): void {
                $query->whereNotIn('cm.ref_type', self::DOCUMENT_MOVEMENT_REF_TYPES)
                    ->orWhere(function ($nested): void {
                        $nested->whereNotNull('cd.id')
                            ->whereNotIn('cd.status', self::EXCLUDED_DOCUMENT_STATUSES);
                    });
            });

        $totalIn = (float) (clone $baseMovements)
            ->whereIn('cm.movement_type', ['IN', 'INCOME'])
            ->sum('cm.amount');
        $totalOut = (float) (clone $baseMovements)
            ->whereIn('cm.movement_type', ['OUT', 'EXPENSE'])
            ->sum('cm.amount');

        DB::table('sales.cash_sessions')
            ->where('id', $sessionId)
            ->update(['expected_balance' => round((float) $session->opening_balance + $totalIn - $totalOut, 4)]);
    }

    private function customerCollectionDescription(object $document): string
    {
        $kind = match (strtoupper((string) ($document->document_kind ?? ''))) {
            'SALES_ORDER' => 'pedido',
            'INVOICE' => 'factura',
            'RECEIPT' => 'boleta',
            'CREDIT_NOTE' => 'nota de crédito',
            'DEBIT_NOTE' => 'nota de débito',
            default => 'documento',
        };

        return sprintf(
            'Cobro de %s %s-%s',
            $kind,
            (string) ($document->series ?? ''),
            (string) ($document->number ?? '')
        );
    }

    public function recalculateCustomerDocumentTotals(int $documentId): void
    {
        $paidTotal = (float) DB::table('sales.commercial_document_payments as p')
            ->join('sales.commercial_documents as d', 'd.id', '=', 'p.document_id')
            ->leftJoin('master.payment_types as pm_doc', 'pm_doc.id', '=', 'd.payment_method_id')
            ->where('p.document_id', $documentId)
            ->where('p.status', 'PAID')
            ->whereRaw('NOT (' . $this->customerAutoIssuedPaidPredicateSql('p', 'd', 'pm_doc') . ')')
            ->sum('p.amount');

        $doc = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->first(['total']);

        if (!$doc) {
            return;
        }

        $total = (float) ($doc->total ?? 0);
        $balance = max(0, round($total - $paidTotal, 2));

        DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->update([
                'paid_total' => round($paidTotal, 2),
                'balance_due' => $balance,
                'updated_at' => now(),
            ]);
    }

    public function paginateSupplierCreditDocuments(int $companyId, ?int $branchId, int $page, int $perPage, ?string $search = null, ?string $paymentStatus = null): array
    {
        $hasStockEntryPayments = $this->hasStockEntryPaymentsTable();
        $hasMetadata = $this->hasStockEntriesColumn('metadata');
        $hasPaymentMethodId = $this->hasStockEntriesColumn('payment_method_id');
        $hasTotalAmount = $this->hasStockEntriesColumn('total_amount');
        $totalExpr = $this->supplierDocumentTotalExpr($hasTotalAmount);
        $hasPaidTotal = $this->hasStockEntriesColumn('paid_total');
        $hasBalanceDue = $this->hasStockEntriesColumn('balance_due');

        $paidExpr = '0';
        $balanceExpr = 'GREATEST(' . $totalExpr . ', 0)';

        if ($hasStockEntryPayments) {
            $paidExpr = 'COALESCE(ps.paid_amount, 0)';
            $balanceExpr = 'GREATEST(' . $totalExpr . ' - COALESCE(ps.paid_amount, 0), 0)';
        } elseif ($hasBalanceDue) {
            $balanceExpr = 'GREATEST(COALESCE(se.balance_due, 0), 0)';
            if ($hasPaidTotal) {
                $paidExpr = 'COALESCE(se.paid_total, 0)';
            } else {
                $paidExpr = 'GREATEST(' . $totalExpr . ' - COALESCE(se.balance_due, 0), 0)';
            }
        } elseif ($hasPaidTotal) {
            $paidExpr = 'COALESCE(se.paid_total, 0)';
            $balanceExpr = 'GREATEST(' . $totalExpr . ' - COALESCE(se.paid_total, 0), 0)';
        }

        $base = DB::table('inventory.stock_entries as se')
            ->select([
                'se.id',
                'se.entry_type as document_kind',
                DB::raw("COALESCE(NULLIF(TRIM(se.reference_no), ''), CONCAT('COMPRA-', se.id::text)) as series"),
                DB::raw('se.id as number'),
                $this->hasStockEntriesColumn('payment_method_id') ? 'se.payment_method_id' : DB::raw('NULL as payment_method_id'),
                'se.issue_at',
                'se.status as document_status',
                DB::raw("COALESCE(NULLIF(TRIM(se.supplier_reference), ''), '-') as counterpart_name"),
                DB::raw($totalExpr . ' as total_amount'),
                DB::raw($paidExpr . ' as paid_amount'),
                DB::raw($balanceExpr . ' as balance_amount'),
            ])
            ->where('se.company_id', $companyId)
            ->whereIn('se.entry_type', ['PURCHASE'])
            ->whereIn('se.status', ['APPLIED', 'OPEN', 'PARTIAL', 'CLOSED'])
            ->where(function ($q) use ($hasMetadata, $hasStockEntryPayments, $hasPaymentMethodId, $hasBalanceDue, $hasPaidTotal, $balanceExpr) {
                $q->whereRaw('1 = 0');

                if ($hasPaymentMethodId) {
                    $q->orWhereRaw("UPPER(translate(COALESCE(pm.name, ''), 'ÁÉÍÓÚáéíóú', 'AEIOUAEIOU')) LIKE '%CREDITO%'");
                }

                if ($hasMetadata) {
                    $q->orWhereRaw("UPPER(COALESCE(se.metadata->>'payment_condition', '')) = 'CREDITO'")
                        ->orWhereRaw("CASE WHEN COALESCE(se.metadata->>'credit_installments_count', '') ~ '^[0-9]+$' THEN (se.metadata->>'credit_installments_count')::int ELSE 0 END > 0");
                }

                if ($hasStockEntryPayments) {
                    $q->orWhereExists(function ($sub) {
                        $sub->select(DB::raw('1'))
                            ->from('inventory.stock_entry_payments as pcred')
                            ->whereColumn('pcred.stock_entry_id', 'se.id')
                            ->where(function ($p) {
                                $p->whereNotNull('pcred.due_at')
                                    ->orWhereRaw("UPPER(COALESCE(pcred.status, '')) = 'PENDING'");
                            });
                    });
                }

                // Fallback only for legacy schemas with no reliable credit markers.
                if (!$hasPaymentMethodId && !$hasMetadata && !$hasStockEntryPayments) {
                    if ($hasBalanceDue) {
                        $q->orWhereRaw("COALESCE(se.balance_due, 0) > 0.0001 AND UPPER(COALESCE(se.reference_no, '')) LIKE 'C%'");
                    } elseif ($hasPaidTotal) {
                        $q->orWhereRaw("COALESCE(se.paid_total, 0) > 0.0001 AND UPPER(COALESCE(se.reference_no, '')) LIKE 'C%'")
                            ->orWhereRaw($balanceExpr . " > 0.0001 AND UPPER(COALESCE(se.reference_no, '')) LIKE 'C%'");
                    } else {
                        $q->orWhereRaw($balanceExpr . " > 0.0001 AND UPPER(COALESCE(se.reference_no, '')) LIKE 'C%'");
                    }
                }
            });

        if ($hasPaymentMethodId) {
            $base->leftJoin('master.payment_types as pm', 'pm.id', '=', 'se.payment_method_id');
        }

        if ($hasStockEntryPayments) {
            $paymentSummary = DB::table('inventory.stock_entry_payments')
                ->selectRaw('stock_entry_id, COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as paid_amount', ['PAID'])
                ->groupBy('stock_entry_id');

            $base->leftJoinSub($paymentSummary, 'ps', function ($join) {
                $join->on('ps.stock_entry_id', '=', 'se.id');
            });
        }

        if ($branchId !== null) {
            $base->where(function ($q) use ($branchId) {
                $q->where('se.branch_id', $branchId)
                    ->orWhereNull('se.branch_id');
            });
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $base->where(function ($q) use ($term) {
                $q->where('se.reference_no', 'ilike', $term)
                    ->orWhere('se.supplier_reference', 'ilike', $term);
            });
        }

        if ($paymentStatus === 'PENDING') {
            $base->whereRaw($balanceExpr . ' > 0.0001');
        } elseif ($paymentStatus === 'CANCELED') {
            $base->whereRaw($balanceExpr . ' <= 0.0001');
        }

        $total = (clone $base)->count();
        $rows = $base
            ->orderBy('se.issue_at', 'desc')
            ->orderBy('se.id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return ['rows' => $rows, 'total' => (int) $total];
    }

    public function listSupplierDocumentPayments(int $companyId, int $stockEntryId): array
    {
        if (!$this->hasStockEntryPaymentsTable()) {
            return [];
        }

        return DB::table('inventory.stock_entry_payments as p')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'p.payment_method_id')
            ->where('p.stock_entry_id', $stockEntryId)
            ->whereExists(function ($q) use ($companyId) {
                $q->select(DB::raw('1'))
                    ->from('inventory.stock_entries as se')
                    ->whereColumn('se.id', 'p.stock_entry_id')
                    ->where('se.company_id', $companyId);
            })
            ->orderByDesc('p.id')
            ->get([
                'p.id',
                'p.stock_entry_id',
                'p.payment_method_id',
                'p.amount',
                'p.status',
                'p.paid_at',
                'p.due_at',
                'p.notes',
                'p.created_at',
                'pm.name as payment_method_name',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function createSupplierDocumentPayment(int $stockEntryId, array $payload): int
    {
        if (!$this->hasStockEntryPaymentsTable()) {
            throw new \RuntimeException('Tu base actual no tiene la tabla inventory.stock_entry_payments. Se puede listar el saldo, pero no registrar pagos por detalle hasta crear esa tabla.', 422);
        }

        $insertPayload = $this->filterPayloadByTableColumns(
            'inventory',
            'stock_entry_payments',
            $payload + ['stock_entry_id' => $stockEntryId]
        );

        return (int) DB::table('inventory.stock_entry_payments')->insertGetId($insertPayload);
    }

    public function updateSupplierDocumentPayment(int $companyId, int $stockEntryId, int $paymentId, array $payload): bool
    {
        if (!$this->hasStockEntryPaymentsTable()) {
            throw new \RuntimeException('Tu base actual no tiene la tabla inventory.stock_entry_payments. Se puede listar el saldo, pero no registrar pagos por detalle hasta crear esa tabla.', 422);
        }

        $updatePayload = $this->filterPayloadByTableColumns('inventory', 'stock_entry_payments', $payload);

        if ($updatePayload === []) {
            return false;
        }

        return DB::table('inventory.stock_entry_payments as p')
            ->where('p.id', $paymentId)
            ->where('p.stock_entry_id', $stockEntryId)
            ->whereExists(function ($q) use ($companyId) {
                $q->select(DB::raw('1'))
                    ->from('inventory.stock_entries as se')
                    ->whereColumn('se.id', 'p.stock_entry_id')
                    ->where('se.company_id', $companyId);
            })
            ->update($updatePayload) > 0;
    }

    public function deleteSupplierDocumentPayment(int $companyId, int $stockEntryId, int $paymentId): bool
    {
        if (!$this->hasStockEntryPaymentsTable()) {
            throw new \RuntimeException('Tu base actual no tiene la tabla inventory.stock_entry_payments. Se puede listar el saldo, pero no registrar pagos por detalle hasta crear esa tabla.', 422);
        }

        return DB::table('inventory.stock_entry_payments as p')
            ->where('p.id', $paymentId)
            ->where('p.stock_entry_id', $stockEntryId)
            ->whereExists(function ($q) use ($companyId) {
                $q->select(DB::raw('1'))
                    ->from('inventory.stock_entries as se')
                    ->whereColumn('se.id', 'p.stock_entry_id')
                    ->where('se.company_id', $companyId);
            })
            ->delete() > 0;
    }

    public function findSupplierDocument(int $companyId, int $stockEntryId): ?object
    {
        $hasStockEntryPayments = $this->hasStockEntryPaymentsTable();
        $hasTotalAmount = $this->hasStockEntriesColumn('total_amount');
        $totalExpr = $this->supplierDocumentTotalExpr($hasTotalAmount);
        $hasPaidTotal = $this->hasStockEntriesColumn('paid_total');
        $hasBalanceDue = $this->hasStockEntriesColumn('balance_due');

        $paidExpr = '0';
        $balanceExpr = 'GREATEST(' . $totalExpr . ', 0)';

        if ($hasStockEntryPayments) {
            $paidExpr = 'COALESCE(ps.paid_amount, 0)';
            $balanceExpr = 'GREATEST(' . $totalExpr . ' - COALESCE(ps.paid_amount, 0), 0)';
        } elseif ($hasBalanceDue) {
            $balanceExpr = 'GREATEST(COALESCE(se.balance_due, 0), 0)';
            if ($hasPaidTotal) {
                $paidExpr = 'COALESCE(se.paid_total, 0)';
            } else {
                $paidExpr = 'GREATEST(' . $totalExpr . ' - COALESCE(se.balance_due, 0), 0)';
            }
        } elseif ($hasPaidTotal) {
            $paidExpr = 'COALESCE(se.paid_total, 0)';
            $balanceExpr = 'GREATEST(' . $totalExpr . ' - COALESCE(se.paid_total, 0), 0)';
        }

        $query = DB::table('inventory.stock_entries as se')
            ->where('se.company_id', $companyId)
            ->where('se.id', $stockEntryId)
            ->select([
                'se.id',
                'se.entry_type as document_kind',
                DB::raw("COALESCE(NULLIF(TRIM(se.reference_no), ''), CONCAT('COMPRA-', se.id::text)) as series"),
                DB::raw('se.id as number'),
                $this->hasStockEntriesColumn('payment_method_id') ? 'se.payment_method_id' : DB::raw('NULL as payment_method_id'),
                DB::raw($totalExpr . ' as total_amount'),
                DB::raw($paidExpr . ' as paid_amount'),
                DB::raw($balanceExpr . ' as balance_amount'),
                DB::raw("COALESCE(NULLIF(TRIM(se.supplier_reference), ''), '-') as counterpart_name"),
            ]);

        if ($hasStockEntryPayments) {
            $paidSub = DB::table('inventory.stock_entry_payments')
                ->selectRaw('stock_entry_id, COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as paid_amount', ['PAID'])
                ->groupBy('stock_entry_id');

            $query->leftJoinSub($paidSub, 'ps', function ($join) {
                $join->on('ps.stock_entry_id', '=', 'se.id');
            });
        }

        return $query->first();
    }

    private function customerCounterpartNameExpr(string $defaultSqlLiteral): string
    {
        if ($this->hasCommercialDocumentsColumn('customer_name')) {
            return "COALESCE(c.legal_name, c.trade_name, d.customer_name, {$defaultSqlLiteral})";
        }

        return "COALESCE(c.legal_name, c.trade_name, {$defaultSqlLiteral})";
    }

    private function hasCommercialDocumentsColumn(string $column): bool
    {
        if ($column === 'customer_name' && $this->hasCommercialDocumentCustomerName !== null) {
            return $this->hasCommercialDocumentCustomerName;
        }

        $exists = DB::table('information_schema.columns')
            ->where('table_schema', 'sales')
            ->where('table_name', 'commercial_documents')
            ->where('column_name', $column)
            ->exists();

        if ($column === 'customer_name') {
            $this->hasCommercialDocumentCustomerName = $exists;
        }

        return $exists;
    }

    private function hasStockEntryPaymentsTable(): bool
    {
        if ($this->hasStockEntryPaymentsTable !== null) {
            return $this->hasStockEntryPaymentsTable;
        }

        $this->hasStockEntryPaymentsTable = DB::table('information_schema.tables')
            ->where('table_schema', 'inventory')
            ->where('table_name', 'stock_entry_payments')
            ->exists();

        return $this->hasStockEntryPaymentsTable;
    }

    public function hasSupplierPaymentsModule(): bool
    {
        return $this->hasStockEntryPaymentsTable();
    }

    private function hasStockEntriesColumn(string $column): bool
    {
        return $this->hasColumn('inventory', 'stock_entries', $column);
    }

    private function supplierDocumentTotalExpr(bool $hasStoredTotalAmount): string
    {
        $hasTaxRate = $this->hasColumn('inventory', 'stock_entry_items', 'tax_rate');

        $lineTotalExpr = $hasTaxRate
            ? '(COALESCE(sei.qty, 0) * COALESCE(sei.unit_cost, 0) * (1 + (COALESCE(sei.tax_rate, 0) / 100.0)))'
            : '(COALESCE(sei.qty, 0) * COALESCE(sei.unit_cost, 0))';

        $storedTotalExpr = $hasStoredTotalAmount ? 'COALESCE(se.total_amount, 0)' : '0';

        return 'COALESCE((SELECT SUM(' . $lineTotalExpr . ') FROM inventory.stock_entry_items as sei WHERE sei.entry_id = se.id), ' . $storedTotalExpr . ', 0)';
    }

    private function hasTable(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function hasColumn(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    private function getTableColumns(string $schema, string $table): array
    {
        $key = $schema . '.' . $table;

        if (isset($this->tableColumnsCache[$key])) {
            return $this->tableColumnsCache[$key];
        }

        $columns = DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->pluck('column_name')
            ->map(fn ($name) => (string) $name)
            ->all();

        $this->tableColumnsCache[$key] = array_fill_keys($columns, true);

        return $this->tableColumnsCache[$key];
    }

    private function filterPayloadByTableColumns(string $schema, string $table, array $payload): array
    {
        $allowedColumns = $this->getTableColumns($schema, $table);

        return array_filter(
            $payload,
            fn ($_, $column) => isset($allowedColumns[$column]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function customerAutoIssuedPaidExpr(): string
    {
        return '(SELECT COALESCE(SUM(p0.amount), 0) FROM sales.commercial_document_payments as p0 '
            . 'WHERE p0.document_id = d.id AND ' . $this->customerAutoIssuedPaidPredicateSql('p0', 'd', 'pm') . ')';
    }

    private function customerAutoIssuedPaidPredicateSql(string $paymentAlias, string $documentAlias, string $paymentMethodAlias): string
    {
        return "UPPER(COALESCE({$paymentAlias}.status, '')) = 'PAID'"
            . " AND {$paymentAlias}.due_at IS NULL"
            . " AND {$paymentAlias}.notes IS NULL"
            . " AND {$paymentAlias}.created_at = {$documentAlias}.created_at"
            . " AND {$paymentAlias}.paid_at = {$documentAlias}.created_at"
            . " AND ABS(COALESCE({$paymentAlias}.amount, 0) - COALESCE({$documentAlias}.total, 0)) <= 0.0001"
            . " AND UPPER(translate(COALESCE({$paymentMethodAlias}.name, ''), 'ÁÉÍÓÚáéíóú', 'AEIOUAEIOU')) LIKE '%CREDITO%'";
    }
}
