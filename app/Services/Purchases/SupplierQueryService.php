<?php

namespace App\Services\Purchases;

use App\Domain\Purchases\Repositories\SupplierRepositoryInterface;

class SupplierQueryService
{
    public function __construct(
        private SupplierRepositoryInterface $supplierRepository
    ) {
    }

    public function listSuppliers(int $companyId, string $search, int $limit, bool $autocomplete): array
    {
        $search = trim($search);
        $limit = max(1, min($limit, $autocomplete ? 12 : 1000));

        return $this->supplierRepository->getSuppliers($companyId, $search, $limit, $autocomplete);
    }
}
