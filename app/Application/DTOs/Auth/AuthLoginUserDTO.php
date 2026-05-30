<?php

namespace App\Application\DTOs\Auth;

final class AuthLoginUserDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?int $branch_id,
        public readonly string $username,
        public readonly string $password_hash,
        public readonly ?string $first_name,
        public readonly ?string $last_name,
        public readonly ?string $email,
        public readonly int $status,
        public readonly int $company_status
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            username: (string) $row->username,
            password_hash: (string) $row->password_hash,
            first_name: isset($row->first_name) ? (string) $row->first_name : null,
            last_name: isset($row->last_name) ? (string) $row->last_name : null,
            email: isset($row->email) ? (string) $row->email : null,
            status: (int) ($row->status ?? 0),
            company_status: (int) ($row->company_status ?? 0),
        );
    }
}
