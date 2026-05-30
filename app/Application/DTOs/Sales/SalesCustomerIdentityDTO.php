<?php

namespace App\Application\DTOs\Sales;

final class SalesCustomerIdentityDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $doc_type,
        public readonly ?string $doc_number,
        public readonly ?int $customer_type_sunat_code
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            doc_type: isset($row->doc_type) ? (string) $row->doc_type : null,
            doc_number: isset($row->doc_number) ? (string) $row->doc_number : null,
            customer_type_sunat_code: isset($row->customer_type_sunat_code) ? (int) $row->customer_type_sunat_code : null,
        );
    }
}
