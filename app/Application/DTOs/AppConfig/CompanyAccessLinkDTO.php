<?php

namespace App\Application\DTOs\AppConfig;

final class CompanyAccessLinkDTO
{
    public function __construct(
        public readonly ?string $access_slug
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            access_slug: isset($row->access_slug) ? (string) $row->access_slug : null,
        );
    }
}
