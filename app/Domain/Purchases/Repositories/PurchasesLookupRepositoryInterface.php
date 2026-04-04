<?php

namespace App\Domain\Purchases\Repositories;

interface PurchasesLookupRepositoryInterface
{
    public function getPaymentMethods(): array;

    public function getTaxCategories(int $companyId): array;

    public function getInventorySettings(int $companyId): array;
}
