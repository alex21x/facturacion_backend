<?php

namespace App\Application\DTOs\Sales;

final class DocumentTotalsDTO
{
    public function __construct(
        public readonly float $subtotal,
        public readonly float $taxTotal,
        public readonly float $discountTotal,
        public readonly float $grandTotal
    ) {
    }
}
