<?php

namespace App\Domain\Sales\Repositories;

use App\Application\DTOs\Sales\SalesDocumentShowDTO;
use App\Application\DTOs\Sales\SalesSeriesCandidateDTO;
use App\Application\DTOs\Sales\SalesSeriesNumberDTO;
use App\Application\DTOs\Sales\SalesSourceDocumentDTO;

interface CommercialDocumentRepositoryInterface
{
    public function findById(int $documentId, int $companyId): ?SalesSourceDocumentDTO;

    public function findByIdWithCompany(int $documentId, int $companyId): ?array;

    public function findDocumentForShow(int $companyId, int $documentId): ?SalesDocumentShowDTO;

    public function getActiveConversions(int $companyId, int $sourceDocumentId): bool;

    public function create(array $data): int;

    public function update(int $documentId, int $companyId, array $data): void;

    public function incrementSeriesNumber(int $seriesId, int $userId): void;

    public function getSeriesNumber(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $warehouseId, ?int $documentKindId = null): ?SalesSeriesNumberDTO;

    public function getSeriesNumberAnyWarehouse(int $companyId, string $documentKind, string $series, ?int $branchId, ?int $documentKindId = null): ?SalesSeriesNumberDTO;

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
    ): ?SalesSeriesCandidateDTO;

    public function findUserFullNameById(int $userId): string;

    public function tableExists(string $qualifiedTable): bool;

    public function tableColumns(string $qualifiedTable): array;
}
