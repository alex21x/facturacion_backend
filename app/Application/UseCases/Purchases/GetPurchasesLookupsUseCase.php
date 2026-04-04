<?php

namespace App\Application\UseCases\Purchases;

use App\Domain\Purchases\Repositories\PurchasesLookupRepositoryInterface;

class GetPurchasesLookupsUseCase
{
    public function __construct(private PurchasesLookupRepositoryInterface $repository)
    {
    }

    public function execute(int $companyId): array
    {
        return [
            'payment_methods' => $this->repository->getPaymentMethods(),
            'tax_categories' => $this->repository->getTaxCategories($companyId),
            'inventory_settings' => $this->repository->getInventorySettings($companyId),
        ];
    }
}
