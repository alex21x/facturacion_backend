<?php

namespace App\Application\UseCases\Finance;

use App\Services\Finance\CreditPaymentsService;

class GetCustomerCreditPaymentsDetailUseCase
{
    public function __construct(private CreditPaymentsService $service)
    {
    }

    public function execute(int $companyId, int $documentId): array
    {
        return $this->service->listCustomerDocumentPayments($companyId, $documentId);
    }
}
