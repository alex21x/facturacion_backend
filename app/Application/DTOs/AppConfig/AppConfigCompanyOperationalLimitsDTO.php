<?php

namespace App\Application\DTOs\AppConfig;

final class AppConfigCompanyOperationalLimitsDTO
{
    public function __construct(
        public readonly int $max_branches_enabled,
        public readonly int $max_warehouses_enabled,
        public readonly int $max_cash_registers_enabled,
        public readonly int $max_cash_registers_per_warehouse
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            max_branches_enabled: (int) ($row->max_branches_enabled ?? 1),
            max_warehouses_enabled: (int) ($row->max_warehouses_enabled ?? 1),
            max_cash_registers_enabled: (int) ($row->max_cash_registers_enabled ?? 1),
            max_cash_registers_per_warehouse: (int) ($row->max_cash_registers_per_warehouse ?? 1),
        );
    }
}
