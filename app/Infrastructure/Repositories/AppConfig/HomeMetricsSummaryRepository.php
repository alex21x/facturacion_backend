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
        return DB::table('sales.commercial_documents as d')
            ->selectRaw($bucketExpression . ' as bucket_key, COALESCE(SUM(COALESCE(d.total, 0)), 0) as amount')
            ->where('d.company_id', $companyId)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
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
