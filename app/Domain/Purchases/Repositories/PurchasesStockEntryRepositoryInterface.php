<?php

namespace App\Domain\Purchases\Repositories;

interface PurchasesStockEntryRepositoryInterface
{
    public function listPaginated(int $companyId, $branchId, array $filters, int $page, int $perPage): array;

    public function listForExport(int $companyId, $branchId, array $filters, bool $includeItems): array;
}
