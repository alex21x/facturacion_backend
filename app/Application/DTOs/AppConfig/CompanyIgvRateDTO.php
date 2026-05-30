<?php

namespace App\Application\DTOs\AppConfig;

final class CompanyIgvRateDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly float $rate_percent,
        public readonly bool $is_active
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            name: (string) $row->name,
            rate_percent: round((float) ($row->rate_percent ?? 0), 4),
            is_active: (bool) ($row->is_active ?? false),
        );
    }
}
