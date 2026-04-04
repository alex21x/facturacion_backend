<?php

namespace App\Domain\Inventory\Repositories;

interface InventoryReadRepositoryInterface
{
    public function getCurrentStock(int $companyId, $warehouseId, $productId): array;

    public function getLots(int $companyId, $warehouseId, $productId, bool $onlyWithStock): array;

    public function getStockEntries(int $companyId, $warehouseId, $entryType, int $limit): array;

    public function getKardex(int $companyId, $productId, $warehouseId, $dateFrom, $dateTo, int $limit): array;
}
