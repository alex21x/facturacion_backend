<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentPaymentRepositoryInterface
{
    public function create(array $data): int;

    public function createBatch(array $rows): void;

    public function deleteByDocumentId(int $documentId): void;
}
