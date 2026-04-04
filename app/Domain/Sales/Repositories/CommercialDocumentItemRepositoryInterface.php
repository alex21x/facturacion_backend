<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentItemRepositoryInterface
{
    public function create(array $data): int;

    public function deleteByDocumentId(int $documentId): void;

    public function getByDocumentId(int $documentId): array;
}
