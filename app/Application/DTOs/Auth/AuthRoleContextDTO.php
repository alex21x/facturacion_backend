<?php

namespace App\Application\DTOs\Auth;

final class AuthRoleContextDTO
{
    public function __construct(
        public readonly ?string $role_code,
        public readonly ?string $role_profile
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            role_code: isset($row->role_code) ? (string) $row->role_code : null,
            role_profile: isset($row->role_profile) ? (string) $row->role_profile : null,
        );
    }
}
