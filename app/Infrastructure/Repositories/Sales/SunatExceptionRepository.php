<?php

namespace App\Infrastructure\Repositories\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SunatExceptionRepository
{
    private const FINAL_SUNAT_STATUSES = [
        'ACCEPTED',
        'SENT_BY_SUMMARY',
    ];

    public function listExceptions(
        int $companyId,
        ?int $branchId,
        ?string $status,
        int $minAgeHours,
        int $minAttempts,
        bool $onlyManualNeeded,
        int $page,
        int $perPage
    ): array {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.company_id', $companyId)
            ->where('d.status', 'ISSUED')
            ->whereNotIn(DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status',''))"), self::FINAL_SUNAT_STATUSES)
            ->select([
                'd.id',
                'd.branch_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status as document_status',
                'd.updated_at',
                'd.metadata',
                DB::raw("COALESCE(NULLIF(c.legal_name, ''), NULLIF(c.trade_name, ''), NULLIF(TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')), ''), 'Sin cliente') as customer_name"),
                DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status','')) as sunat_status"),
                DB::raw("COALESCE(NULLIF(d.metadata->>'sunat_status_label',''), NULLIF(d.metadata->>'sunat_bridge_note',''), 'Pendiente SUNAT') as sunat_label"),
                DB::raw("COALESCE((d.metadata->>'sunat_reconcile_attempts')::int, 0) as reconcile_attempts"),
                DB::raw("(SELECT COUNT(*)::int FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id) as bridge_attempts"),
                DB::raw("(SELECT UPPER(COALESCE(l.sunat_status, '')) FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id ORDER BY COALESCE(l.sent_at, l.created_at) DESC, l.id DESC LIMIT 1) as bridge_last_status"),
                DB::raw("(SELECT COALESCE(l.sent_at, l.created_at) FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id ORDER BY COALESCE(l.sent_at, l.created_at) DESC, l.id DESC LIMIT 1) as bridge_last_at"),
                DB::raw("COALESCE((d.metadata->>'inventory_pending_sunat')::boolean, false) as inventory_pending_sunat"),
                DB::raw("COALESCE((d.metadata->>'inventory_sunat_settled')::boolean, false) as inventory_sunat_settled"),
                DB::raw("COALESCE((d.metadata->>'sunat_needs_manual_confirmation')::boolean, false) as needs_manual_confirmation"),
                DB::raw("GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - d.updated_at)) / 3600))::int as pending_hours"),
            ]);

        $this->applyTributaryDocumentKindFilter($query, 'd.document_kind');

        if ($branchId !== null) {
            $query->where('d.branch_id', $branchId);
        }

        if ($status !== null && $status !== '') {
            $normalizedStatus = strtoupper(trim($status));

            if ($normalizedStatus === 'PENDING_CONFIRMATION') {
                $query->whereIn(DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status',''))"), [
                    '',
                    'PENDING_CONFIRMATION',
                    'PENDING',
                    'NOT_SENT',
                    'PENDING_SUMMARY',
                    'PENDING_MANUAL',
                ]);
            } else {
                $query->where(DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status',''))"), $normalizedStatus);
            }
        }

        if ($minAgeHours > 0) {
            $query->whereRaw('EXTRACT(EPOCH FROM (NOW() - d.updated_at)) >= ?', [$minAgeHours * 3600]);
        }

        if ($minAttempts > 0) {
            $query->whereRaw("GREATEST(COALESCE((d.metadata->>'sunat_reconcile_attempts')::int, 0), (SELECT COUNT(*)::int FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id)) >= ?", [$minAttempts]);
        }

        if ($onlyManualNeeded) {
            $query->whereRaw("COALESCE((d.metadata->>'sunat_needs_manual_confirmation')::boolean, false) = true");
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('pending_hours')
            ->orderByDesc('d.updated_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'total' => $total,
            'rows' => $rows,
        ];
    }

    public function listAuditRows(
        int $companyId,
        ?int $branchId,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit
    ): Collection {
        $query = DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.status', 'ISSUED')
            ->select([
                'd.id',
                'd.branch_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.updated_at',
                'd.metadata',
                DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status','')) as sunat_status"),
                DB::raw("COALESCE((d.metadata->>'inventory_sunat_settled')::boolean, false) as inventory_sunat_settled"),
                DB::raw("COALESCE((d.metadata->>'inventory_pending_sunat')::boolean, false) as inventory_pending_sunat"),
            ]);

        $this->applyTributaryDocumentKindFilter($query, 'd.document_kind');

        if ($branchId !== null) {
            $query->where('d.branch_id', $branchId);
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $query->whereDate('d.issue_at', '>=', $dateFrom);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $query->whereDate('d.issue_at', '<=', $dateTo);
        }

        return $query
            ->orderByDesc('d.issue_at')
            ->limit(max(1, min($limit, 500)))
            ->get();
    }

    public function runInTransaction(callable $callback)
    {
        return DB::transaction($callback);
    }

    public function lockCommercialDocument(int $companyId, int $documentId): ?\App\Application\DTOs\Sales\SalesDocumentScopeDTO
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        return $document ? \App\Application\DTOs\Sales\SalesDocumentScopeDTO::fromRow($document) : null;
    }

    public function sunatExceptionActionsTableExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'sales')
            ->where('table_name', 'sunat_exception_actions')
            ->exists();
    }

    public function insertSunatExceptionAction(array $payload): bool
    {
        return DB::table('sales.sunat_exception_actions')->insert($payload);
    }

    private function applyTributaryDocumentKindFilter($query, string $column): void
    {
        $query->where(function ($nested) use ($column) {
            $nested->whereRaw("UPPER($column) = 'INVOICE'")
                ->orWhereRaw("UPPER($column) = 'RECEIPT'")
                ->orWhereRaw("UPPER($column) = 'CREDIT_NOTE'")
                ->orWhereRaw("UPPER($column) = 'DEBIT_NOTE'")
                ->orWhereRaw("UPPER($column) LIKE 'CREDIT_NOTE_%'")
                ->orWhereRaw("UPPER($column) LIKE 'DEBIT_NOTE_%'");
        });
    }
}
