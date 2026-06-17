<?php

namespace App\Application\UseCases\Finance;

use App\Services\Finance\CreditPaymentsService;

class UpsertCustomerCreditPaymentUseCase
{
    public function __construct(private CreditPaymentsService $service)
    {
    }

    public function execute(object $authUser, int $companyId, int $documentId, array $payload, ?int $paymentId = null): array
    {
        if ($paymentId !== null && $paymentId > 0) {
            return $this->service->updateCustomerPayment($authUser, $companyId, $documentId, $paymentId, $payload);
        }

        return $this->service->createCustomerPayment($authUser, $companyId, $documentId, $payload);
    }
}
