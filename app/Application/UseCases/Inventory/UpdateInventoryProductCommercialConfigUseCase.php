<?php

namespace App\Application\UseCases\Inventory;

use App\Application\Commands\Inventory\UpdateInventoryProductCommercialConfigCommand;
use App\Domain\Inventory\Repositories\InventoryProductCommercialRepositoryInterface;

class UpdateInventoryProductCommercialConfigUseCase
{
    public function __construct(private InventoryProductCommercialRepositoryInterface $repository)
    {
    }

    public function execute(UpdateInventoryProductCommercialConfigCommand $command): void
    {
        $this->repository->updateProductCommercialConfig(
            $command->authUser,
            $command->companyId,
            $command->productId,
            $command->payload
        );
    }
}
