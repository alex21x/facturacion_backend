<?php

namespace App\Application\UseCases\Inventory;

use App\Domain\Inventory\Repositories\ProductLookupRepositoryInterface;

class GetProductLookupsUseCase
{
    public function __construct(private ProductLookupRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId): array
    {
        return $this->repository->getProductLookups($companyId);
    }
}
