<?php

namespace App\Application\DTOs\Sales;

final class SalesSeriesCandidateDTO
{
    public function __construct(
        public readonly ?string $series
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            series: isset($row->series) ? (string) $row->series : null,
        );
    }
}
