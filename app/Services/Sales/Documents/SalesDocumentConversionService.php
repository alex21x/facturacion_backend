<?php

namespace App\Services\Sales\Documents;

use App\Application\DTOs\Sales\SalesSeriesCandidateDTO;
use App\Application\DTOs\Sales\SalesSourceDocumentDTO;
use App\Domain\Sales\Repositories\CommercialDocumentItemLotRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;

class SalesDocumentConversionService
{
    public function __construct(
        private CommercialDocumentRepositoryInterface $commercialDocumentRepository,
        private CommercialDocumentItemRepositoryInterface $commercialDocumentItemRepository,
        private CommercialDocumentItemLotRepositoryInterface $commercialDocumentItemLotRepository
    ) {
    }

    public function findSourceDocument(int $companyId, int $sourceId): ?SalesSourceDocumentDTO
    {
        return $this->commercialDocumentRepository->findById($sourceId, $companyId);
    }

    public function alreadyConvertedToTarget(int $companyId, int $sourceId, string $targetDocumentKind): bool
    {
        return $this->commercialDocumentRepository->existsConvertedTargetForSource($companyId, $sourceId, $targetDocumentKind);
    }

    public function getSourceItems(int $sourceId)
    {
        return $this->commercialDocumentItemRepository->getOrderedRowsByDocumentId($sourceId);
    }

    public function getLotsGroupedByItemIds(array $sourceItemIds)
    {
        return $this->commercialDocumentItemLotRepository->getGroupedByDocumentItemIds($sourceItemIds);
    }

    public function findCandidateSeries(
        int $companyId,
        string $targetDocumentKindCode,
        int $targetDocumentKindId,
        ?int $branchId,
        ?int $warehouseId
    ): ?SalesSeriesCandidateDTO {
        return $this->commercialDocumentRepository->findFirstEnabledSeriesForTargetKind(
            $companyId,
            $targetDocumentKindCode,
            $targetDocumentKindId,
            $branchId,
            $warehouseId
        );
    }

    public function getValidProductIdMap(int $companyId, array $productIds): array
    {
        return $this->commercialDocumentItemRepository->getActiveProductIdMap($companyId, $productIds);
    }

    public function resolveUserFullName(int $userId): string
    {
        return $this->commercialDocumentRepository->findUserFullNameById($userId);
    }
}
