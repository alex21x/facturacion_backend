<?php

namespace App\Application\DTOs\Auth;

final class AuthRefreshSessionDTO
{
    public function __construct(
        public readonly int $session_id,
        public readonly int $user_id,
        public readonly ?string $token_hash,
        public readonly ?string $expires_at,
        public readonly ?string $revoked_at,
        public readonly int $company_id,
        public readonly ?int $branch_id,
        public readonly string $username,
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
            session_id: (int) $row->session_id,
            user_id: (int) $row->user_id,
            token_hash: isset($row->token_hash) ? (string) $row->token_hash : null,
            expires_at: isset($row->expires_at) ? (string) $row->expires_at : null,
            revoked_at: isset($row->revoked_at) ? (string) $row->revoked_at : null,
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            username: (string) $row->username,
            first_name: isset($row->first_name) ? (string) $row->first_name : null,
            last_name: isset($row->last_name) ? (string) $row->last_name : null,
            email: isset($row->email) ? (string) $row->email : null,
            status: (int) ($row->status ?? 0),
            company_status: (int) ($row->company_status ?? 0),
        );
    }
}
