<?php

namespace App\Application\DTOs\AppConfig;

final class AppConfigStationContextDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly int $cash_register_id,
        public readonly ?int $branch_id,
        public readonly ?int $warehouse_id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $device_id,
        public readonly ?string $device_name,
        public readonly int $status,
        public readonly string $cash_register_code,
        public readonly string $cash_register_name
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            cash_register_id: (int) $row->cash_register_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            warehouse_id: isset($row->warehouse_id) ? (int) $row->warehouse_id : null,
            code: (string) $row->code,
            name: (string) $row->name,
            device_id: (string) $row->device_id,
            device_name: isset($row->device_name) ? (string) $row->device_name : null,
            status: (int) ($row->status ?? 0),
            cash_register_code: (string) $row->cash_register_code,
            cash_register_name: (string) $row->cash_register_name,
        );
    }
}
