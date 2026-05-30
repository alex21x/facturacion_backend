<?php

namespace App\Application\DTOs\Auth;

final class AuthRefreshSessionRecordDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $user_id,
        public readonly ?string $expires_at,
        public readonly ?string $revoked_at
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            user_id: (int) $row->user_id,
            expires_at: isset($row->expires_at) ? (string) $row->expires_at : null,
            revoked_at: isset($row->revoked_at) ? (string) $row->revoked_at : null,
        );
    }
}
