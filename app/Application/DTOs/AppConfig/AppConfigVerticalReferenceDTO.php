<?php

namespace App\Application\DTOs\AppConfig;

final class AppConfigVerticalReferenceDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $code,
        public readonly ?string $name
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            code: isset($row->code) ? (string) $row->code : null,
            name: isset($row->name) ? (string) $row->name : null,
        );
    }
}
