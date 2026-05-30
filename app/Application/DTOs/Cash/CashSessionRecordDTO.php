<?php

namespace App\Application\DTOs\Cash;

final class CashSessionRecordDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $company_id,
        public readonly ?int $branch_id,
        public readonly ?int $cash_register_id,
        public readonly ?int $user_id,
        public readonly ?int $opened_by,
        public readonly ?int $closed_by,
        public readonly ?string $opened_at,
        public readonly ?string $closed_at,
        public readonly float $opening_balance,
        public readonly ?float $closing_balance,
        public readonly float $expected_balance,
        public readonly ?string $status,
        public readonly ?string $notes,
        public readonly ?string $created_at,
        public readonly ?string $updated_at
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: isset($row->company_id) ? (int) $row->company_id : null,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            cash_register_id: isset($row->cash_register_id) ? (int) $row->cash_register_id : null,
            user_id: isset($row->user_id) ? (int) $row->user_id : null,
            opened_by: isset($row->opened_by) ? (int) $row->opened_by : null,
            closed_by: isset($row->closed_by) ? (int) $row->closed_by : null,
            opened_at: isset($row->opened_at) ? (string) $row->opened_at : null,
            closed_at: isset($row->closed_at) ? (string) $row->closed_at : null,
            opening_balance: round((float) ($row->opening_balance ?? 0), 4),
            closing_balance: isset($row->closing_balance) ? round((float) $row->closing_balance, 4) : null,
            expected_balance: round((float) ($row->expected_balance ?? 0), 4),
            status: isset($row->status) ? (string) $row->status : null,
            notes: isset($row->notes) ? (string) $row->notes : null,
            created_at: isset($row->created_at) ? (string) $row->created_at : null,
            updated_at: isset($row->updated_at) ? (string) $row->updated_at : null,
        );
    }
}
