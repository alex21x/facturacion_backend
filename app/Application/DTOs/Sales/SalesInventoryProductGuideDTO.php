<?php

namespace App\Application\DTOs\Sales;

final class SalesInventoryProductGuideDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?float $cost_price,
        public readonly ?string $name
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            cost_price: isset($row->cost_price) ? (float) $row->cost_price : null,
            name: isset($row->name) ? (string) $row->name : null,
        );
    }
}
