<?php

namespace App\Application\DTOs\AppConfig;

final class CompanyProfileDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $tax_id,
        public readonly ?string $legal_name,
        public readonly ?string $trade_name,
        public readonly ?string $email,
        public readonly ?string $contact_email,
        public readonly int $status
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            tax_id: isset($row->tax_id) ? (string) $row->tax_id : null,
            legal_name: isset($row->legal_name) ? (string) $row->legal_name : null,
            trade_name: isset($row->trade_name) ? (string) $row->trade_name : null,
            email: isset($row->email) ? (string) $row->email : null,
            contact_email: isset($row->contact_email) ? (string) $row->contact_email : null,
            status: (int) ($row->status ?? 0),
        );
    }
}
