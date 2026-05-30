<?php

namespace App\Application\DTOs\AppConfig;

final class BackupCompanyDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $tax_id,
        public readonly ?string $legal_name
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            tax_id: isset($row->tax_id) ? (string) $row->tax_id : null,
            legal_name: isset($row->legal_name) ? (string) $row->legal_name : null,
        );
    }
}
