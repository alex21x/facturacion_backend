<?php

namespace App\Application\DTOs\Sales;

final class SalesAuditLogScopeDTO
{
    public function __construct(
        public readonly int $company_id,
        public readonly ?int $branch_id
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
        );
    }
}
