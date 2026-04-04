<?php

namespace App\Application\UseCases\Purchases;

use App\Application\Commands\Purchases\ExportPurchasesStockEntriesCommand;
use App\Domain\Purchases\Repositories\PurchasesStockEntryRepositoryInterface;

class ExportPurchasesStockEntriesUseCase
{
    public function __construct(private PurchasesStockEntryRepositoryInterface $repository)
    {
    }

    public function execute(ExportPurchasesStockEntriesCommand $command): array
    {
        return $this->repository->listForExport(
            $command->companyId,
            $command->branchId,
            $command->filters(),
            $command->includeItems
        );
    }
}
