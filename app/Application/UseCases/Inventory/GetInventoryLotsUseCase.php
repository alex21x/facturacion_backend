<?php

namespace App\Application\UseCases\Inventory;

use App\Domain\Inventory\Repositories\InventoryReadRepositoryInterface;

class GetInventoryLotsUseCase
{
    public function __construct(private InventoryReadRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId, $warehouseId, $productId, bool $onlyWithStock): array
    {
        return $this->repository->getLots($companyId, $warehouseId, $productId, $onlyWithStock);
    }
}
