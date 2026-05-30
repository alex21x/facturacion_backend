<?php

namespace App\Application\DTOs\Sales;

final class SalesSeriesNumberDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $current_number,
        public readonly ?string $series
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            current_number: (int) ($row->current_number ?? 0),
            series: isset($row->series) ? (string) $row->series : null,
        );
    }
}
