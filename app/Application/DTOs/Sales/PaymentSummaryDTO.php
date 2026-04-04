<?php

namespace App\Application\DTOs\Sales;

final class PaymentSummaryDTO
{
    public function __construct(
        public readonly float $paidTotal,
        public readonly float $paymentTotal,
        public readonly array $pendingPayments
    ) {
    }
}
