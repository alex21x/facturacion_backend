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

    public function createBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('sales.commercial_document_payments')->insert($rows);
    }

    public function listPaidByDocumentId(int $documentId): array
    {
        return DB::table('sales.commercial_document_payments')
            ->where('document_id', $documentId)
            ->whereRaw("UPPER(COALESCE(status, '')) = 'PAID'")
            ->orderBy('id')
            ->get([
                'id',
                'payment_method_id',
                'amount',
                'status',
                'paid_at',
                'due_at',
                'notes',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function transferPaidToDocument(int $sourceDocumentId, int $targetDocumentId): int
    {
        // Keep payment IDs stable so linked customer-collection cash movements remain attached.
        return DB::table('sales.commercial_document_payments')
            ->where('document_id', $sourceDocumentId)
            ->whereRaw("UPPER(COALESCE(status, '')) = 'PAID'")
            ->update(['document_id' => $targetDocumentId]);
    }

    public function deleteByDocumentId(int $documentId): void
    {
        DB::table('sales.commercial_document_payments')
            ->where('document_id', $documentId)
            ->delete();
    }
}
