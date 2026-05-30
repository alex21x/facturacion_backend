<?php

namespace App\Application\DTOs\Sales;

final class SalesDocumentShowDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $branch_id,
        public readonly ?int $warehouse_id,
        public readonly ?int $customer_id,
        public readonly ?int $customer_vehicle_id,
        public readonly ?int $currency_id,
        public readonly ?int $payment_method_id,
        public readonly ?string $document_kind,
        public readonly ?string $series,
        public readonly ?int $number,
        public readonly ?string $issue_at,
        public readonly ?string $due_at,
        public readonly ?string $status,
        public readonly ?float $subtotal,
        public readonly ?float $tax_total,
        public readonly ?float $total,
        public readonly ?float $balance_due,
        public readonly ?string $notes,
        public readonly ?string $metadata,
        public readonly ?string $vehicle_plate_snapshot,
        public readonly ?string $vehicle_brand_snapshot,
        public readonly ?string $vehicle_model_snapshot,
        public readonly ?string $currency_code,
        public readonly ?string $currency_symbol,
        public readonly ?string $payment_method_name,
        public readonly ?string $customer_name,
        public readonly ?string $customer_doc_number,
        public readonly ?string $customer_address
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            warehouse_id: isset($row->warehouse_id) ? (int) $row->warehouse_id : null,
            customer_id: isset($row->customer_id) ? (int) $row->customer_id : null,
            customer_vehicle_id: isset($row->customer_vehicle_id) ? (int) $row->customer_vehicle_id : null,
            currency_id: isset($row->currency_id) ? (int) $row->currency_id : null,
            payment_method_id: isset($row->payment_method_id) ? (int) $row->payment_method_id : null,
            document_kind: isset($row->document_kind) ? (string) $row->document_kind : null,
            series: isset($row->series) ? (string) $row->series : null,
            number: isset($row->number) ? (int) $row->number : null,
            issue_at: isset($row->issue_at) ? (string) $row->issue_at : null,
            due_at: isset($row->due_at) ? (string) $row->due_at : null,
            status: isset($row->status) ? (string) $row->status : null,
            subtotal: isset($row->subtotal) ? (float) $row->subtotal : null,
            tax_total: isset($row->tax_total) ? (float) $row->tax_total : null,
            total: isset($row->total) ? (float) $row->total : null,
            balance_due: isset($row->balance_due) ? (float) $row->balance_due : null,
            notes: isset($row->notes) ? (string) $row->notes : null,
            metadata: isset($row->metadata) ? (string) $row->metadata : null,
            vehicle_plate_snapshot: isset($row->vehicle_plate_snapshot) ? (string) $row->vehicle_plate_snapshot : null,
            vehicle_brand_snapshot: isset($row->vehicle_brand_snapshot) ? (string) $row->vehicle_brand_snapshot : null,
            vehicle_model_snapshot: isset($row->vehicle_model_snapshot) ? (string) $row->vehicle_model_snapshot : null,
            currency_code: isset($row->currency_code) ? (string) $row->currency_code : null,
            currency_symbol: isset($row->currency_symbol) ? (string) $row->currency_symbol : null,
            payment_method_name: isset($row->payment_method_name) ? (string) $row->payment_method_name : null,
            customer_name: isset($row->customer_name) ? (string) $row->customer_name : null,
            customer_doc_number: isset($row->customer_doc_number) ? (string) $row->customer_doc_number : null,
            customer_address: isset($row->customer_address) ? (string) $row->customer_address : null,
        );
    }
}
