<?php

namespace App\Application\UseCases\Inventory;

use App\Application\Commands\Inventory\CreateInventoryStockEntryCommand;
use App\Domain\Inventory\Repositories\InventoryStockEntryRepositoryInterface;

class CreateInventoryStockEntryUseCase
{
    public function __construct(private InventoryStockEntryRepositoryInterface $repository)
    {
    }

    public function execute(CreateInventoryStockEntryCommand $command): array
    {
        return $this->repository->createAppliedStockEntry(
            $command->authUser,
            $command->payload,
            $command->companyId,
            $command->branchId,
            $command->warehouseId
        );
    }
}
