<?php

namespace App\Application\DTOs\Sales;

final class SalesCustomerPriceProfileDTO
{
    public function __construct(
        public readonly ?int $default_tier_id,
        public readonly float $discount_percent,
        public readonly int $status
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            default_tier_id: isset($row->default_tier_id) ? (int) $row->default_tier_id : null,
            discount_percent: (float) ($row->discount_percent ?? 0),
            status: (int) ($row->status ?? 1),
        );
    }
}
