<?php

namespace App\Application\DTOs\AppConfig;

final class CompanyFeatureToggleDTO
{
    public function __construct(
        public readonly bool $is_enabled,
        public readonly ?string $config
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            is_enabled: (bool) ($row->is_enabled ?? false),
            config: isset($row->config) ? (string) $row->config : null,
        );
    }
}
