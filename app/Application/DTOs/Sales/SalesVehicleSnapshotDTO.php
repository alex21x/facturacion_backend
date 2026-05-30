<?php

namespace App\Application\DTOs\Sales;

final class SalesVehicleSnapshotDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $customer_id,
        public readonly ?string $plate,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?int $year,
        public readonly ?string $color,
        public readonly ?string $vin,
        public readonly ?bool $is_default,
        public readonly ?int $status
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) ($row->id ?? 0),
            customer_id: isset($row->customer_id) ? (int) $row->customer_id : null,
            plate: isset($row->plate) ? (string) $row->plate : null,
            brand: isset($row->brand) ? (string) $row->brand : null,
            model: isset($row->model) ? (string) $row->model : null,
            year: isset($row->year) ? (int) $row->year : null,
            color: isset($row->color) ? (string) $row->color : null,
            vin: isset($row->vin) ? (string) $row->vin : null,
            is_default: isset($row->is_default) ? (bool) $row->is_default : null,
            status: isset($row->status) ? (int) $row->status : null,
        );
    }
}
