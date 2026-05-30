<?php

namespace App\Application\DTOs\Sales;

final class SalesDocumentScopeDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?int $branch_id,
        public readonly ?string $metadata = null
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            metadata: isset($row->metadata) ? (string) $row->metadata : null,
        );
    }
}
