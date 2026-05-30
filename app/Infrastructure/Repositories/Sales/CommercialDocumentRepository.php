<?php

namespace App\Infrastructure\Repositories\Sales;

use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Infrastructure\Models\Sales\CommercialDocument;
use App\Infrastructure\Models\Sales\SeriesNumber;
use Illuminate\Support\Facades\DB;

class CommercialDocumentRepository implements CommercialDocumentRepositoryInterface
{
    public function findById(int $documentId, int $companyId): ?\App\Application\DTOs\Sales\SalesSourceDocumentDTO
    {
        $document = CommercialDocument::forCompany($companyId)
            ->where('id', $documentId)
            ->first();

        return $document ? \App\Application\DTOs\Sales\SalesSourceDocumentDTO::fromRow($document) : null;
    }

    public function findByIdWithCompany(int $documentId, int $companyId): ?array
    {
        $document = CommercialDocument::forCompany($companyId)
            ->where('id', $documentId)
            ->with('items.lots', 'payments')
            ->first();

        return $document ? $document->toArray() : null;
    }

    public function findDocumentForShow(int $companyId, int $documentId): ?\App\Application\DTOs\Sales\SalesDocumentShowDTO
    {
        $row = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('core.currencies as cur', 'cur.id', '=', 'd.currency_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->select([
                'd.id',
                'd.branch_id',
                'd.warehouse_id',
                'd.customer_id',
                'd.customer_vehicle_id',
                'd.currency_id',
                'd.payment_method_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.due_at',
                'd.status',
                'd.subtotal',
                'd.tax_total',
                'd.total',
                'd.balance_due',
                'd.notes',
                'd.metadata',
                'd.vehicle_plate_snapshot',
                'd.vehicle_brand_snapshot',
                'd.vehicle_model_snapshot',
                'cur.code as currency_code',
                'cur.symbol as currency_symbol',
                'pm.name as payment_method_name',
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                'c.doc_number as customer_doc_number',
                'c.address as customer_address',
            ])
            ->where('d.id', $documentId)
            ->where('d.company_id', $companyId)
            ->first();

        return $row ? \App\Application\DTOs\Sales\SalesDocumentShowDTO::fromRow($row) : null;
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

    public function getSeriesNumber(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $warehouseId, ?int $documentKindId = null): ?\App\Application\DTOs\Sales\SalesSeriesNumberDTO
    {
        $seriesNumber = SeriesNumber::query()
            ->forCompany($companyId)
            ->forDocumentSeries($documentKind, $series, $documentKindId)
            ->enabled()
            ->forBranchAndWarehouse($branchId, $warehouseId)
            ->lockForUpdate()
            ->first();

        return $seriesNumber ? \App\Application\DTOs\Sales\SalesSeriesNumberDTO::fromRow($seriesNumber) : null;
    }

    public function getSeriesNumberAnyWarehouse(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $documentKindId = null): ?\App\Application\DTOs\Sales\SalesSeriesNumberDTO
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

        $seriesNumber = $query->lockForUpdate()->first();

        return $seriesNumber ? \App\Application\DTOs\Sales\SalesSeriesNumberDTO::fromRow($seriesNumber) : null;
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

    public function getDocumentTotalById(int $companyId, int $documentId): float
    {
        return (float) (DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->where('id', $documentId)
            ->value('total') ?? 0);
    }

    public function getAppliedNoteTotalForSource(int $companyId, int $sourceDocumentId, string $documentKind): float
    {
        return (float) (DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.document_kind', $documentKind)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceDocumentId])
            ->sum('d.total'));
    }

    public function findSourceDocumentIdFromMetadata(int $companyId, int $documentId): ?int
    {
        $row = DB::table('sales.commercial_documents')
            ->select('metadata')
            ->where('company_id', $companyId)
            ->where('id', $documentId)
            ->first();

        if (!$row || $row->metadata === null || $row->metadata === '') {
            return null;
        }

        $metadata = json_decode((string) $row->metadata, true);
        if (!is_array($metadata)) {
            return null;
        }

        $sourceDocumentId = (int) ($metadata['source_document_id'] ?? 0);

        return $sourceDocumentId > 0 ? $sourceDocumentId : null;
    }

    public function existsConvertedTargetForSource(int $companyId, int $sourceDocumentId, string $targetDocumentKind): bool
    {
        return DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.document_kind', $targetDocumentKind)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceDocumentId])
            ->exists();
    }

    public function findFirstEnabledSeriesForTargetKind(
        int $companyId,
        string $targetDocumentKindCode,
        int $targetDocumentKindId,
        ?int $branchId,
        ?int $warehouseId
    ): ?\App\Application\DTOs\Sales\SalesSeriesCandidateDTO {
        $row = DB::table('sales.series_numbers')
            ->where('company_id', $companyId)
            ->where(function ($query) use ($targetDocumentKindId, $targetDocumentKindCode) {
                if ($targetDocumentKindId > 0) {
                    $query->where('document_kind_id', $targetDocumentKindId)
                        ->orWhere(function ($legacy) use ($targetDocumentKindCode) {
                            $legacy->whereNull('document_kind_id')
                                ->where('document_kind', $targetDocumentKindCode);
                        });
                    return;
                }

                $query->where('document_kind', $targetDocumentKindCode);
            })
            ->where('is_enabled', true)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->when($warehouseId !== null, function ($query) use ($warehouseId) {
                $query->where(function ($nested) use ($warehouseId) {
                    $nested->where('warehouse_id', $warehouseId)
                        ->orWhereNull('warehouse_id');
                });
            })
            ->orderByDesc('branch_id')
            ->orderByDesc('warehouse_id')
            ->orderBy('series')
            ->first();

        return $row ? \App\Application\DTOs\Sales\SalesSeriesCandidateDTO::fromRow($row) : null;
    }

    public function findUserFullNameById(int $userId): string
    {
        return trim((string) (DB::table('auth.users')
            ->where('id', $userId)
            ->selectRaw("TRIM(COALESCE(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')), '')) as full_name")
            ->value('full_name') ?? ''));
    }

    public function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        return isset($row->present) && (bool) $row->present;
    }

    public function tableColumns(string $qualifiedTable): array
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        return collect(DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [$schema, $table]
        ))->map(function ($row) {
            return (string) $row->column_name;
        })->all();
    }

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') === false) {
            return ['public', $qualifiedTable];
        }

        [$schema, $table] = explode('.', $qualifiedTable, 2);

        return [$schema, $table];
    }
}
