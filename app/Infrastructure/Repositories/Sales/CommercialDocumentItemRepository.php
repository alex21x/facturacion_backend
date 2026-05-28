<?php

namespace App\Infrastructure\Repositories\Sales;

use App\Domain\Sales\Repositories\CommercialDocumentItemRepositoryInterface;
use App\Infrastructure\Models\Sales\CommercialDocumentItem;
use Illuminate\Support\Facades\DB;

class CommercialDocumentItemRepository implements CommercialDocumentItemRepositoryInterface
{
    public function create(array $data): int
    {
        return CommercialDocumentItem::create($data)->id;
    }

    public function deleteByDocumentId(int $documentId): void
    {
        CommercialDocumentItem::where('document_id', $documentId)->delete();
    }

    public function getByDocumentId(int $documentId): array
    {
        return CommercialDocumentItem::where('document_id', $documentId)
            ->get()
            ->toArray();
    }

    public function createInventoryLedgerEntry(array $data): void
    {
        DB::table('inventory.inventory_ledger')->insert($data);
    }

    public function getOrderedRowsByDocumentId(int $documentId)
    {
        return DB::table('sales.commercial_document_items')
            ->where('document_id', $documentId)
            ->orderBy('line_no')
            ->get();
    }

    public function getDetailedRowsByDocumentId(int $documentId, bool $includeProductCode = false, ?string $productCodeColumn = null)
    {
        $query = DB::table('sales.commercial_document_items as i')
            ->leftJoin('core.units as u', 'u.id', '=', 'i.unit_id');

        if ($includeProductCode && $productCodeColumn !== null && $productCodeColumn !== '') {
            $query->leftJoin('inventory.products as p', 'p.id', '=', 'i.product_id');
        }

        $selectColumns = [
            'i.id',
            'i.line_no',
            'i.product_id',
            'i.unit_id',
            'i.price_tier_id',
            'i.qty',
            'i.qty_base',
            'i.conversion_factor',
            'i.base_unit_price',
            'i.description',
            'i.unit_price',
            'i.unit_cost',
            'i.wholesale_discount_percent',
            'i.price_source',
            'i.discount_total',
            'u.code as unit_code',
            'i.tax_category_id',
            'i.tax_total',
            'i.subtotal',
            'i.total',
            'i.metadata',
        ];

        if ($includeProductCode && $productCodeColumn !== null && $productCodeColumn !== '') {
            $selectColumns[] = 'p.' . $productCodeColumn . ' as product_code';
        }

        return $query
            ->select($selectColumns)
            ->where('i.document_id', $documentId)
            ->orderBy('i.line_no')
            ->get();
    }

    public function getActiveProductIdMap(int $companyId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
