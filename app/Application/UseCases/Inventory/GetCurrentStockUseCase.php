<?php

namespace App\Application\UseCases\Inventory;

use App\Domain\Inventory\Repositories\InventoryReadRepositoryInterface;

class GetCurrentStockUseCase
{
    public function __construct(private InventoryReadRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId, $warehouseId, $productId): array
    {
        return $this->repository->getCurrentStock($companyId, $warehouseId, $productId);
    }
}
