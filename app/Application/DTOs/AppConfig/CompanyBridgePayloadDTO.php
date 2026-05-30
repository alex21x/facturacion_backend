<?php

namespace App\Application\DTOs\AppConfig;

final class CompanyBridgePayloadDTO
{
    public function __construct(
        public readonly ?string $tax_id,
        public readonly ?string $legal_name,
        public readonly ?string $trade_name
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            tax_id: isset($row->tax_id) ? (string) $row->tax_id : null,
            legal_name: isset($row->legal_name) ? (string) $row->legal_name : null,
            trade_name: isset($row->trade_name) ? (string) $row->trade_name : null,
        );
    }
}
