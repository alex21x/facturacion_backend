<?php

namespace App\Application\DTOs\Sales;

final class PosStationConflictDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?string $code
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            code: isset($row->code) ? (string) $row->code : null,
        );
    }
}
