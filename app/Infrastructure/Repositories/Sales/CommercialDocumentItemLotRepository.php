<?php

namespace App\Infrastructure\Repositories\Sales;

use App\Domain\Sales\Repositories\CommercialDocumentItemLotRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CommercialDocumentItemLotRepository implements CommercialDocumentItemLotRepositoryInterface
{
    public function create(array $data): void
    {
        DB::table('sales.commercial_document_item_lots')->insert($data);
    }

    public function deleteByDocumentIds(array $documentItemIds): void
    {
        if (!empty($documentItemIds)) {
            DB::table('sales.commercial_document_item_lots')
                ->whereIn('document_item_id', $documentItemIds)
                ->delete();
        }
    }
}
