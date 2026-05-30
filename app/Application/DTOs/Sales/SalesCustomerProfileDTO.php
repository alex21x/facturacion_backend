<?php

namespace App\Application\DTOs\Sales;

final class SalesCustomerProfileDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $doc_type,
        public readonly ?int $customer_type_id,
        public readonly ?string $customer_type_name,
        public readonly ?int $customer_type_sunat_code,
        public readonly ?string $doc_number,
        public readonly ?string $legal_name,
        public readonly ?string $trade_name,
        public readonly ?string $first_name,
        public readonly ?string $last_name,
        public readonly ?string $plate,
        public readonly ?string $address,
        public readonly ?string $phone,
        public readonly ?int $default_tier_id,
        public readonly ?float $discount_percent,
        public readonly ?int $price_profile_status,
        public readonly ?string $default_tier_code,
        public readonly ?string $default_tier_name
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            doc_type: isset($row->doc_type) ? (string) $row->doc_type : null,
            customer_type_id: isset($row->customer_type_id) ? (int) $row->customer_type_id : null,
            customer_type_name: isset($row->customer_type_name) ? (string) $row->customer_type_name : null,
            customer_type_sunat_code: isset($row->customer_type_sunat_code) ? (int) $row->customer_type_sunat_code : null,
            doc_number: isset($row->doc_number) ? (string) $row->doc_number : null,
            legal_name: isset($row->legal_name) ? (string) $row->legal_name : null,
            trade_name: isset($row->trade_name) ? (string) $row->trade_name : null,
            first_name: isset($row->first_name) ? (string) $row->first_name : null,
            last_name: isset($row->last_name) ? (string) $row->last_name : null,
            plate: isset($row->plate) ? (string) $row->plate : null,
            address: isset($row->address) ? (string) $row->address : null,
            phone: isset($row->phone) ? (string) $row->phone : null,
            default_tier_id: isset($row->default_tier_id) ? (int) $row->default_tier_id : null,
            discount_percent: isset($row->discount_percent) ? (float) $row->discount_percent : null,
            price_profile_status: isset($row->price_profile_status) ? (int) $row->price_profile_status : null,
            default_tier_code: isset($row->default_tier_code) ? (string) $row->default_tier_code : null,
            default_tier_name: isset($row->default_tier_name) ? (string) $row->default_tier_name : null,
        );
    }
}
