<?php

namespace App\Application\UseCases\Inventory;

use App\Domain\Inventory\Repositories\InventoryProductCommercialRepositoryInterface;

class GetInventoryProductCommercialConfigUseCase
{
    public function __construct(private InventoryProductCommercialRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId, int $productId): ?array
    {
        return $this->repository->getProductCommercialConfig($companyId, $productId);
    }
}
