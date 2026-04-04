<?php

namespace App\Application\UseCases\Purchases;

use App\Application\Commands\Purchases\ListPurchasesStockEntriesCommand;
use App\Domain\Purchases\Repositories\PurchasesStockEntryRepositoryInterface;

class ListPurchasesStockEntriesUseCase
{
    public function __construct(private PurchasesStockEntryRepositoryInterface $repository)
    {
    }

    public function execute(ListPurchasesStockEntriesCommand $command): array
    {
        return $this->repository->listPaginated(
            $command->companyId,
            $command->branchId,
            $command->filters(),
            $command->page,
            $command->perPage
        );
    }
}
