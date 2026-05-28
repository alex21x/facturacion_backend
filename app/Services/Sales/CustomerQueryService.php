<?php

namespace App\Services\Sales;

use App\Domain\Sales\Repositories\CustomerRepositoryInterface;

class CustomerQueryService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function listCustomers(
        int $companyId,
        string $search,
        $status,
        int $limit,
        bool $autocomplete,
        bool $workshopVehicleSearchEnabled
    ): array {
        $search = trim($search);
        $limit = max(1, min($limit, $autocomplete ? 30 : 10000));

        return $this->customerRepository->getCustomers(
            $companyId,
            $search,
            $status,
            $limit,
            $autocomplete,
            $workshopVehicleSearchEnabled
        );
    }
}
