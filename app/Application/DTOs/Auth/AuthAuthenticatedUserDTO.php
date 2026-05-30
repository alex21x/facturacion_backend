<?php

namespace App\Application\DTOs\Auth;

final class AuthAuthenticatedUserDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?int $branch_id,
        public readonly ?int $preferred_warehouse_id,
        public readonly ?int $preferred_cash_register_id,
        public readonly string $username,
        public readonly ?string $first_name,
        public readonly ?string $last_name,
        public readonly ?string $email,
        public readonly int $status,
        public readonly ?string $role_code = null,
        public readonly ?string $role_profile = null
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            preferred_warehouse_id: isset($row->preferred_warehouse_id) ? (int) $row->preferred_warehouse_id : null,
            preferred_cash_register_id: isset($row->preferred_cash_register_id) ? (int) $row->preferred_cash_register_id : null,
            username: (string) $row->username,
            first_name: isset($row->first_name) ? (string) $row->first_name : null,
            last_name: isset($row->last_name) ? (string) $row->last_name : null,
            email: isset($row->email) ? (string) $row->email : null,
            status: (int) ($row->status ?? 0),
            role_code: isset($row->role_code) ? (string) $row->role_code : null,
            role_profile: isset($row->role_profile) ? (string) $row->role_profile : null,
        );
    }

    public function withRoleContext(?string $roleCode, ?string $roleProfile): self
    {
        return new self(
            id: $this->id,
            company_id: $this->company_id,
            branch_id: $this->branch_id,
            preferred_warehouse_id: $this->preferred_warehouse_id,
            preferred_cash_register_id: $this->preferred_cash_register_id,
            username: $this->username,
            first_name: $this->first_name,
            last_name: $this->last_name,
            email: $this->email,
            status: $this->status,
            role_code: $roleCode,
            role_profile: $roleProfile,
        );
    }
}
