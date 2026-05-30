<?php

namespace App\Application\DTOs\Purchases;

final class PurchaseSupplierDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $doc_type,
        public readonly ?string $doc_number,
        public readonly ?string $legal_name,
        public readonly ?string $address,
        public readonly ?string $phone,
        public readonly ?string $source
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            doc_type: isset($row->doc_type) ? (string) $row->doc_type : null,
            doc_number: isset($row->doc_number) ? (string) $row->doc_number : null,
            legal_name: isset($row->legal_name) ? (string) $row->legal_name : null,
            address: isset($row->address) ? (string) $row->address : null,
            phone: isset($row->phone) ? (string) $row->phone : null,
            source: isset($row->source) ? (string) $row->source : null,
        );
    }
}
