<?php

namespace App\Domain\Inventory\Repositories;

interface InventoryProductCommercialRepositoryInterface
{
    public function getProductCommercialConfig(int $companyId, int $productId): ?array;

    public function updateProductCommercialConfig(object $authUser, int $companyId, int $productId, array $payload): void;
}
