<?php

namespace App\Application\DTOs\Cash;

final class CashSessionDetailDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $cash_register_id,
        public readonly ?string $cash_register_code,
        public readonly ?string $cash_register_name,
        public readonly ?int $user_id,
        public readonly ?string $user_name,
        public readonly ?string $opened_at,
        public readonly ?string $closed_at,
        public readonly float $opening_balance,
        public readonly ?float $closing_balance,
        public readonly float $expected_balance,
        public readonly ?string $status,
        public readonly ?string $notes,
        public readonly ?int $branch_id
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            cash_register_id: isset($row->cash_register_id) ? (int) $row->cash_register_id : null,
            cash_register_code: isset($row->cash_register_code) ? (string) $row->cash_register_code : null,
            cash_register_name: isset($row->cash_register_name) ? (string) $row->cash_register_name : null,
            user_id: isset($row->user_id) ? (int) $row->user_id : null,
            user_name: isset($row->user_name) ? (string) $row->user_name : null,
            opened_at: isset($row->opened_at) ? (string) $row->opened_at : null,
            closed_at: isset($row->closed_at) ? (string) $row->closed_at : null,
            opening_balance: round((float) ($row->opening_balance ?? 0), 4),
            closing_balance: isset($row->closing_balance) ? round((float) $row->closing_balance, 4) : null,
            expected_balance: round((float) ($row->expected_balance ?? 0), 4),
            status: isset($row->status) ? (string) $row->status : null,
            notes: isset($row->notes) ? (string) $row->notes : null,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
        );
    }
}
