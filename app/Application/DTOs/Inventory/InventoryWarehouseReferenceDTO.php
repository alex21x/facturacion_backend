<?php

namespace App\Application\DTOs\Inventory;

final class InventoryWarehouseReferenceDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $code
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            code: isset($row->code) ? (string) $row->code : null,
        );
    }
}
