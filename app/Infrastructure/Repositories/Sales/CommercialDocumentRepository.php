<?php

namespace App\Infrastructure\Repositories\Sales;

use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Infrastructure\Models\Sales\CommercialDocument;
use App\Infrastructure\Models\Sales\SeriesNumber;
use Illuminate\Support\Facades\DB;

class CommercialDocumentRepository implements CommercialDocumentRepositoryInterface
{
    public function findById(int $documentId, int $companyId): ?object
    {
        return CommercialDocument::forCompany($companyId)
            ->where('id', $documentId)
            ->first();
    }

    public function findByIdWithCompany(int $documentId, int $companyId): ?object
    {
        return CommercialDocument::forCompany($companyId)
            ->where('id', $documentId)
            ->with('items.lots', 'payments')
            ->first();
    }

    public function getActiveConversions(int $companyId, int $sourceDocumentId): bool
    {
        return CommercialDocument::query()
            ->forCompany($companyId)
            ->excludeCanceledStatuses()
            ->forSourceDocument($sourceDocumentId)
            ->exists();
    }

    public function create(array $data): int
    {
        return CommercialDocument::create($data)->id;
    }

    public function update(int $documentId, int $companyId, array $data): void
    {
        CommercialDocument::where('id', $documentId)
            ->where('company_id', $companyId)
            ->update($data);
    }

    public function incrementSeriesNumber(int $seriesId, int $userId): void
    {
        DB::table('sales.series_numbers')
            ->where('id', $seriesId)
            ->increment('current_number', 1, [
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);
    }

    public function getSeriesNumber(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $warehouseId, ?int $documentKindId = null): ?object
    {
        return SeriesNumber::query()
            ->forCompany($companyId)
            ->forDocumentSeries($documentKind, $series, $documentKindId)
            ->enabled()
            ->forBranchAndWarehouse($branchId, $warehouseId)
            ->lockForUpdate()
            ->first();
    }

    public function getSeriesNumberAnyWarehouse(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $documentKindId = null): ?object
    {
        $query = SeriesNumber::query()
            ->forCompany($companyId)
            ->forDocumentSeries($documentKind, $series, $documentKindId)
            ->enabled();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } else {
            $query->whereNull('branch_id');
        }

        return $query->lockForUpdate()->first();
    }

    public function deleteItemsAndPayments(int $documentId): void
    {
        $itemIds = DB::table('sales.commercial_document_items')
            ->where('document_id', $documentId)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (!empty($itemIds)) {
            DB::table('sales.commercial_document_item_lots')
                ->whereIn('document_item_id', $itemIds)
                ->delete();
        }

        DB::table('sales.commercial_document_items')
            ->where('document_id', $documentId)
            ->delete();

        DB::table('sales.commercial_document_payments')
            ->where('document_id', $documentId)
            ->delete();
    }
}
