<?php

namespace App\Domain\Sales\Repositories;

interface CommercialDocumentRepositoryInterface
{
    public function findById(int $documentId, int $companyId): ?object;

    public function findByIdWithCompany(int $documentId, int $companyId): ?object;

    public function findDocumentForShow(int $companyId, int $documentId): ?object;

    public function getActiveConversions(int $companyId, int $sourceDocumentId): bool;

    public function create(array $data): int;

    public function update(int $documentId, int $companyId, array $data): void;

    public function incrementSeriesNumber(int $seriesId, int $userId): void;

    public function getSeriesNumber(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $warehouseId, ?int $documentKindId = null): ?object;

    public function getSeriesNumberAnyWarehouse(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $documentKindId = null): ?object;

    public function deleteItemsAndPayments(int $documentId): void;

    public function getDocumentTotalById(int $companyId, int $documentId): float;

    public function getAppliedNoteTotalForSource(int $companyId, int $sourceDocumentId, string $documentKind): float;

    public function findSourceDocumentIdFromMetadata(int $companyId, int $documentId): ?int;

    public function existsConvertedTargetForSource(int $companyId, int $sourceDocumentId, string $targetDocumentKind): bool;

    public function findFirstEnabledSeriesForTargetKind(
        int $companyId,
        string $targetDocumentKindCode,
        int $targetDocumentKindId,
        ?int $branchId,
        ?int $warehouseId
    ): ?object;

    public function findUserFullNameById(int $userId): string;

    public function tableExists(string $qualifiedTable): bool;

    public function tableColumns(string $qualifiedTable): array;
}
