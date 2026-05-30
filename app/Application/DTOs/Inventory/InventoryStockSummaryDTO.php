<?php

namespace App\Application\DTOs\Inventory;

final class InventoryStockSummaryDTO
{
    public function __construct(
        public readonly int $rows,
        public readonly float $total_qty,
        public readonly float $total_value
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            rows: (int) ($row->rows ?? 0),
            total_qty: (float) ($row->total_qty ?? 0),
            total_value: (float) ($row->total_value ?? 0),
        );
    }
}
