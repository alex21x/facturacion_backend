<?php

namespace App\Application\DTOs\AppConfig;

final class AppConfigPlatformLimitsDTO
{
    public function __construct(
        public readonly int $max_companies_enabled
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            max_companies_enabled: (int) ($row->max_companies_enabled ?? 0),
        );
    }
}
