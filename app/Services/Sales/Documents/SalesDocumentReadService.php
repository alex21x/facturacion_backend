<?php

namespace App\Services\Sales\Documents;

use App\Domain\Sales\Repositories\CommercialDocumentItemLotRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentItemRepositoryInterface;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;

class SalesDocumentReadService
{
    public function __construct(
        private CommercialDocumentRepositoryInterface $commercialDocumentRepository,
        private CommercialDocumentItemRepositoryInterface $commercialDocumentItemRepository,
        private CommercialDocumentItemLotRepositoryInterface $commercialDocumentItemLotRepository
    ) {
    }

    public function findDocumentForShow(int $companyId, int $documentId): ?object
    {
        return $this->commercialDocumentRepository->findDocumentForShow($companyId, $documentId);
    }

    public function resolveDocumentItemsWithFallback(int $companyId, int $documentId, int $maxDepth = 5)
    {
        $visited = [];
        $currentDocumentId = $documentId;
        $productTableExists = $this->commercialDocumentRepository->tableExists('inventory.products');
        $productCodeColumn = null;

        if ($productTableExists) {
            $productColumns = $this->commercialDocumentRepository->tableColumns('inventory.products');
            $productCodeColumn = $this->firstExistingColumn($productColumns, ['code', 'sku', 'internal_code']);
        }

        for ($depth = 0; $depth <= $maxDepth; $depth++) {
            if (in_array($currentDocumentId, $visited, true)) {
                break;
            }
            $visited[] = $currentDocumentId;

            $items = $this->commercialDocumentItemRepository->getDetailedRowsByDocumentId(
                $currentDocumentId,
                $productTableExists && $productCodeColumn !== null,
                $productCodeColumn
            );

            if (!$items->isEmpty()) {
                return $items;
            }

            $nextDocumentId = $this->commercialDocumentRepository->findSourceDocumentIdFromMetadata($companyId, $currentDocumentId);
            if ($nextDocumentId === null || $nextDocumentId <= 0) {
                break;
            }

            $currentDocumentId = $nextDocumentId;
        }

        return collect();
    }

    public function getLotsGroupedByItemIds(array $itemIds)
    {
        return $this->commercialDocumentItemLotRepository->getGroupedByDocumentItemIds($itemIds);
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
