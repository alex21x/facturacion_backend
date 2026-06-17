<?php

namespace App\Application\UseCases\Finance;

use App\Services\Finance\CreditPaymentsService;

class BuildCreditPaymentTicketUseCase
{
    public function __construct(private CreditPaymentsService $service)
    {
    }

    public function execute(string $title, array $document, array $payment): string
    {
        return $this->service->paymentTicketHtml($title, $document, $payment);
    }
}
