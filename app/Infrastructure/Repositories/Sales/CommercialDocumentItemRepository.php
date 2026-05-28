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
}
