<?php

namespace App\Application\DTOs\Inventory;

final class InventoryStockLevelDTO
{
    public function __construct(
        public readonly float $stock
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            stock: (float) ($row->stock ?? 0),
        );
    }
}
