<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentPaymentRepositoryInterface
{
    public function create(array $data): int;

    public function createBatch(array $rows): void;

    public function listPaidByDocumentId(int $documentId): array;

    public function transferPaidToDocument(int $sourceDocumentId, int $targetDocumentId): int;

    public function deleteByDocumentId(int $documentId): void;
}
