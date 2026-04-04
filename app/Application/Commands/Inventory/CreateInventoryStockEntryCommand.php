<?php

namespace App\Application\Commands\Inventory;

final class CreateInventoryStockEntryCommand
{
    public function __construct(
        public readonly object $authUser,
        public readonly array $payload,
        public readonly int $companyId,
        public readonly mixed $branchId,
        public readonly int $warehouseId
    ) {
    }

    public static function fromInput(
        object $authUser,
        array $payload,
        int $companyId,
        mixed $branchId,
        int $warehouseId
    ): self {
        return new self($authUser, $payload, $companyId, $branchId, $warehouseId);
    }
}
