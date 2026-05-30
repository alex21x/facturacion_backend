<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentItemRepositoryInterface
{
    public function create(array $data): int;

    public function deleteByDocumentId(int $documentId): void;

    public function getByDocumentId(int $documentId): array;

    public function createInventoryLedgerEntry(array $data): void;

    public function getOrderedRowsByDocumentId(int $documentId);

    public function getDetailedRowsByDocumentId(int $documentId, bool $includeProductCode = false, ?string $productCodeColumn = null);

    public function getActiveProductIdMap(int $companyId, array $productIds): array;
}
