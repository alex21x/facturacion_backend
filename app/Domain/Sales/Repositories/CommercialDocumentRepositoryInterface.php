<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentRepositoryInterface
{
    public function findById(int $documentId, int $companyId): ?object;

    public function findByIdWithCompany(int $documentId, int $companyId): ?object;

    public function getActiveConversions(int $companyId, int $sourceDocumentId): bool;

    public function create(array $data): int;

    public function update(int $documentId, int $companyId, array $data): void;

    public function incrementSeriesNumber(int $seriesId, int $userId): void;

    public function getSeriesNumber(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $warehouseId, ?int $documentKindId = null): ?object;

    public function deleteItemsAndPayments(int $documentId): void;
}
