<?php

namespace App\Infrastructure\Repositories\Sales;

use App\Domain\Sales\Repositories\CommercialDocumentPaymentRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CommercialDocumentPaymentRepository implements CommercialDocumentPaymentRepositoryInterface
{
    public function create(array $data): int
    {
        return DB::table('sales.commercial_document_payments')->insertGetId($data);
    }

    public function deleteByDocumentId(int $documentId): void
    {
        DB::table('sales.commercial_document_payments')
            ->where('document_id', $documentId)
            ->delete();
    }
}
