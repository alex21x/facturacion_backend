<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentPaymentRepositoryInterface
{
    public function create(array $data): int;

    public function deleteByDocumentId(int $documentId): void;
}
