<?php

namespace App\Application\UseCases\Inventory;

use App\Domain\Inventory\Repositories\InventoryReadRepositoryInterface;

class GetInventoryKardexUseCase
{
    public function __construct(private InventoryReadRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId, $productId, $warehouseId, $dateFrom, $dateTo, int $perPage, int $page): array
    {
        return $this->repository->getKardex($companyId, $productId, $warehouseId, $dateFrom, $dateTo, $perPage, $page);
    }
}
