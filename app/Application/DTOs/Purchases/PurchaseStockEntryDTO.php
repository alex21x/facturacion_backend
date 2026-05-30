<?php

namespace App\Application\DTOs\Purchases;

final class PurchaseStockEntryDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $branch_id,
        public readonly ?int $warehouse_id,
        public readonly ?int $payment_method_id,
        public readonly ?string $entry_type,
        public readonly ?string $status,
        public readonly ?string $issue_at,
        public readonly ?string $reference_no,
        public readonly ?string $supplier_reference,
        public readonly ?string $notes,
        public readonly mixed $metadata
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            warehouse_id: isset($row->warehouse_id) ? (int) $row->warehouse_id : null,
            payment_method_id: isset($row->payment_method_id) ? (int) $row->payment_method_id : null,
            entry_type: isset($row->entry_type) ? (string) $row->entry_type : null,
            status: isset($row->status) ? (string) $row->status : null,
            issue_at: isset($row->issue_at) ? (string) $row->issue_at : null,
            reference_no: isset($row->reference_no) ? (string) $row->reference_no : null,
            supplier_reference: isset($row->supplier_reference) ? (string) $row->supplier_reference : null,
            notes: isset($row->notes) ? (string) $row->notes : null,
            metadata: $row->metadata ?? null,
        );
    }
}
