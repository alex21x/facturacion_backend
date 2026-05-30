<?php

namespace App\Application\DTOs\Cash;

final class CashSessionScopeDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?int $branch_id,
        public readonly ?int $cash_register_id,
        public readonly ?string $opened_at,
        public readonly ?string $closed_at
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            cash_register_id: isset($row->cash_register_id) ? (int) $row->cash_register_id : null,
            opened_at: isset($row->opened_at) ? (string) $row->opened_at : null,
            closed_at: isset($row->closed_at) ? (string) $row->closed_at : null,
        );
    }
}
