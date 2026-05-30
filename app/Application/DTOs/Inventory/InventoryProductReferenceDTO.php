<?php

namespace App\Application\DTOs\Inventory;

final class InventoryProductReferenceDTO
{
    public function __construct(
        public readonly int $id
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
        );
    }
}
