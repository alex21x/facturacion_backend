<?php

namespace App\Application\UseCases\Finance;

use App\Services\Finance\CreditPaymentsService;

class PaginateSupplierCreditDocumentsUseCase
{
    public function __construct(private CreditPaymentsService $service)
    {
    }

    public function execute(int $companyId, ?int $branchId, int $page, int $perPage, ?string $search, ?string $paymentStatus): array
    {
        return $this->service->paginateSupplierCreditDocuments($companyId, $branchId, $page, $perPage, $search, $paymentStatus);
    }
}
