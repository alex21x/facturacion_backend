<?php

namespace App\Application\Commands\Inventory;

final class UpdateInventoryProductCommercialConfigCommand
{
    public function __construct(
        public readonly object $authUser,
        public readonly int $companyId,
        public readonly int $productId,
        public readonly array $payload
    ) {
    }

    public static function fromInput(object $authUser, int $companyId, int $productId, array $payload): self
    {
        return new self($authUser, $companyId, $productId, $payload);
    }
}
