<?php

namespace App\Application\DTOs\Sales;

final class SalesSourceDocumentDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?int $customer_id,
        public readonly ?string $document_kind,
        public readonly ?string $series,
        public readonly ?int $number,
        public readonly ?string $status,
        public readonly ?int $branch_id,
        public readonly ?int $warehouse_id,
        public readonly ?string $metadata,
        public readonly ?int $created_by,
        public readonly ?int $payment_method_id,
        public readonly ?int $currency_id,
        public readonly ?float $exchange_rate,
        public readonly ?float $subtotal,
        public readonly ?float $tax_total,
        public readonly ?float $discount_total,
        public readonly ?float $total,
        public readonly ?string $notes,
        public readonly ?string $due_at
    ) {
    }

    public static function fromRow(object $row): self
    {
        $toNullableString = static function ($value): ?string {
            if ($value === null) {
                return null;
            }

            if (is_array($value) || is_object($value)) {
                $encoded = json_encode($value);
                return $encoded !== false ? $encoded : null;
            }

            return (string) $value;
        };

        $metadata = null;
        if (isset($row->metadata)) {
            if (is_array($row->metadata) || is_object($row->metadata)) {
                $encoded = json_encode($row->metadata);
                $metadata = $encoded !== false ? $encoded : null;
            } else {
                $metadata = (string) $row->metadata;
            }
        }

        return new self(
            id: (int) $row->id,
            company_id: isset($row->company_id) ? (int) $row->company_id : 0,
            customer_id: isset($row->customer_id) ? (int) $row->customer_id : null,
            document_kind: isset($row->document_kind) ? $toNullableString($row->document_kind) : null,
            series: isset($row->series) ? $toNullableString($row->series) : null,
            number: isset($row->number) ? (int) $row->number : null,
            status: isset($row->status) ? $toNullableString($row->status) : null,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            warehouse_id: isset($row->warehouse_id) ? (int) $row->warehouse_id : null,
            metadata: $metadata,
            created_by: isset($row->created_by) ? (int) $row->created_by : null,
            payment_method_id: isset($row->payment_method_id) ? (int) $row->payment_method_id : null,
            currency_id: isset($row->currency_id) ? (int) $row->currency_id : null,
            exchange_rate: isset($row->exchange_rate) ? (float) $row->exchange_rate : null,
            subtotal: isset($row->subtotal) ? (float) $row->subtotal : null,
            tax_total: isset($row->tax_total) ? (float) $row->tax_total : null,
            discount_total: isset($row->discount_total) ? (float) $row->discount_total : null,
            total: isset($row->total) ? (float) $row->total : null,
            notes: isset($row->notes) ? $toNullableString($row->notes) : null,
            due_at: isset($row->due_at) ? $toNullableString($row->due_at) : null,
        );
    }
}
