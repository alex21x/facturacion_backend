<?php

namespace App\Application\DTOs\Sales;

final class SalesCustomerIdentityRecordDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $status
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            status: (int) ($row->status ?? 0),
        );
    }
}
