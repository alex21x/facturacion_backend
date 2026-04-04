<?php

namespace App\Domain\Inventory\Repositories;

interface ProductLookupRepositoryInterface
{
    public function getProductLookups(int $companyId): array;
}
