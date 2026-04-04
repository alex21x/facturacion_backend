<?php

namespace App\Application\Commands\Sales;

final class CreateCommercialDocumentCommand
{
    public function __construct(
        public readonly object $authUser,
        public readonly array $payload,
        public readonly int $companyId,
        public readonly ?int $branchId,
        public readonly ?int $warehouseId,
        public readonly ?int $cashRegisterId
    ) {
    }

    public static function fromInput(
        object $authUser,
        array $payload,
        int $companyId,
        ?int $branchId,
        ?int $warehouseId,
        ?int $cashRegisterId
    ): self {
        return new self($authUser, $payload, $companyId, $branchId, $warehouseId, $cashRegisterId);
    }
}
