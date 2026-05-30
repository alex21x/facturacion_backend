<?php

namespace App\Application\DTOs\Cash;

final class CashMovementDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?int $branch_id,
        public readonly ?int $cash_register_id,
        public readonly ?int $cash_session_id,
        public readonly string $movement_type,
        public readonly ?int $payment_method_id,
        public readonly float $amount,
        public readonly ?string $description,
        public readonly ?string $notes,
        public readonly ?string $ref_type,
        public readonly ?int $ref_id,
        public readonly ?int $created_by,
        public readonly ?int $user_id,
        public readonly ?string $movement_at,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
        public readonly ?string $user_name
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            cash_register_id: isset($row->cash_register_id) ? (int) $row->cash_register_id : null,
            cash_session_id: isset($row->cash_session_id) ? (int) $row->cash_session_id : null,
            movement_type: (string) (($row->movement_type_ui ?? null) ?: ($row->movement_type ?? '')),
            payment_method_id: isset($row->payment_method_id) ? (int) $row->payment_method_id : null,
            amount: round((float) ($row->amount ?? 0), 4),
            description: isset($row->description) ? (string) $row->description : null,
            notes: isset($row->notes) ? (string) $row->notes : null,
            ref_type: isset($row->ref_type) ? (string) $row->ref_type : null,
            ref_id: isset($row->ref_id) ? (int) $row->ref_id : null,
            created_by: isset($row->created_by) ? (int) $row->created_by : null,
            user_id: isset($row->user_id) ? (int) $row->user_id : null,
            movement_at: isset($row->movement_at) ? (string) $row->movement_at : null,
            created_at: isset($row->created_at) ? (string) $row->created_at : null,
            updated_at: isset($row->updated_at) ? (string) $row->updated_at : null,
            user_name: isset($row->user_name) ? (string) $row->user_name : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'cash_register_id' => $this->cash_register_id,
            'cash_session_id' => $this->cash_session_id,
            'movement_type' => $this->movement_type,
            'payment_method_id' => $this->payment_method_id,
            'amount' => $this->amount,
            'description' => $this->description,
            'notes' => $this->notes,
            'ref_type' => $this->ref_type,
            'ref_id' => $this->ref_id,
            'created_by' => $this->created_by,
            'user_id' => $this->user_id,
            'movement_at' => $this->movement_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user_name' => $this->user_name,
        ];
    }
}
