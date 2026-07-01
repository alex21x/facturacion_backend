<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Facades\DB;

class HomeMetricsSummaryRepository
{
    public function aggregateSalesByBucket(
        int $companyId,
        string $bucketExpression,
        string $from,
        string $to,
        ?int $branchId,
        ?int $warehouseId
    ) {
        $documentKindBaseExpr = "CASE
            WHEN UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind)) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE'
            WHEN UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind)) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE'
            ELSE UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind))
        END";

        return DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.document_kinds as dk_id', 'dk_id.id', '=', 'd.document_kind_id')
            ->leftJoin('sales.document_kinds as dk_legacy', function ($join): void {
                $join->on(DB::raw('UPPER(dk_legacy.code)'), '=', DB::raw('UPPER(d.document_kind)'));
            })
            ->selectRaw($bucketExpression . " as bucket_key, COALESCE(SUM(CASE
                WHEN ({$documentKindBaseExpr}) = 'CREDIT_NOTE' THEN -ABS(COALESCE(d.total, 0))
                ELSE ABS(COALESCE(d.total, 0))
            END), 0) as amount")
            ->where('d.company_id', $companyId)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("({$documentKindBaseExpr}) IN ('INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE')")
            ->whereBetween('d.issue_at', [$from, $to])
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where('d.branch_id', $branchId);
            })
            ->when($warehouseId !== null, function ($query) use ($warehouseId) {
                $query->where('d.warehouse_id', $warehouseId);
            })
            ->groupBy('bucket_key')
            ->pluck('amount', 'bucket_key');
    }

    public function aggregatePurchasesByBucket(
        int $companyId,
        string $bucketExpression,
        string $from,
        string $to,
        ?int $branchId,
        ?int $warehouseId
    ) {
        return DB::table('inventory.stock_entries as se')
            ->join('inventory.stock_entry_items as sei', 'sei.entry_id', '=', 'se.id')
            ->selectRaw($bucketExpression . ' as bucket_key, COALESCE(SUM(COALESCE(sei.qty, 0) * COALESCE(sei.unit_cost, 0)), 0) as amount')
            ->where('se.company_id', $companyId)
            ->whereIn('se.status', ['APPLIED', 'OPEN', 'PARTIAL', 'CLOSED'])
            ->whereBetween('se.issue_at', [$from, $to])
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('se.branch_id', $branchId)
                        ->orWhereNull('se.branch_id');
                });
            })
            ->when($warehouseId !== null, function ($query) use ($warehouseId) {
                $query->where('se.warehouse_id', $warehouseId);
            })
            ->groupBy('bucket_key')
            ->pluck('amount', 'bucket_key');
    }
}
