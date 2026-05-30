<?php

namespace App\Application\DTOs\AppConfig;

final class AdminCompanyUserDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly ?string $email,
        public readonly ?string $last_temp_password
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            username: (string) $row->username,
            email: isset($row->email) ? (string) $row->email : null,
            last_temp_password: isset($row->last_temp_password) ? (string) $row->last_temp_password : null,
        );
    }
}
