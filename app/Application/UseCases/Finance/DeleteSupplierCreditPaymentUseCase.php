<?php

namespace App\Application\UseCases\Finance;

use App\Services\Finance\CreditPaymentsService;

class DeleteSupplierCreditPaymentUseCase
{
    public function __construct(private CreditPaymentsService $service)
    {
    }

    public function execute(int $companyId, int $documentId, int $paymentId): array
    {
        return $this->service->deleteSupplierPayment($companyId, $documentId, $paymentId);
    }
}
