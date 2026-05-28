<?php

namespace App\Domain\Inventory\Repositories;

interface InventoryProductRepositoryInterface
{
    public function getProducts(int $companyId, string $search, $status, int $limit, bool $autocomplete): array;
}