<?php

namespace App\Application\UseCases\Inventory;

use App\Domain\Inventory\Repositories\InventoryReadRepositoryInterface;

class GetInventoryStockEntriesUseCase
{
    public function __construct(private InventoryReadRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId, $warehouseId, $entryType, int $limit): array
    {
        return $this->repository->getStockEntries($companyId, $warehouseId, $entryType, $limit);
    }
}
