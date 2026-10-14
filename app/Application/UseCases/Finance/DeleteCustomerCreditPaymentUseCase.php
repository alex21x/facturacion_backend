<?php

namespace App\Application\UseCases\Finance;

use App\Services\Finance\CreditPaymentsService;

class DeleteCustomerCreditPaymentUseCase
{
    public function __construct(private CreditPaymentsService $service)
    {
    }

    public function execute(int $companyId, int $documentId, int $paymentId): array
    {
        return $this->service->deleteCustomerPayment($companyId, $documentId, $paymentId);
    }
}
