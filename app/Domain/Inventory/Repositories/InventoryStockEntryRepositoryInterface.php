<?php

namespace App\Domain\Inventory\Repositories;

interface InventoryStockEntryRepositoryInterface
{
    public function createAppliedStockEntry(object $authUser, array $payload, int $companyId, $branchId, int $warehouseId): array;
}
