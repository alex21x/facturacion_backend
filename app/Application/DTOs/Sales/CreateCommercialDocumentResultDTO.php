<?php

namespace App\Application\DTOs\Sales;

final class CreateCommercialDocumentResultDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $documentKind,
        public readonly string $series,
        public readonly int $number,
        public readonly string $issueAt,
        public readonly float $total,
        public readonly float $paidTotal,
        public readonly float $balanceDue,
        public readonly string $status,
        public readonly ?int $branchId,
        public readonly ?int $warehouseId,
        public readonly ?int $cashRegisterId
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_kind' => $this->documentKind,
            'series' => $this->series,
            'number' => $this->number,
            'issue_at' => $this->issueAt,
            'total' => $this->total,
            'paid_total' => $this->paidTotal,
            'balance_due' => $this->balanceDue,
            'status' => $this->status,
            'branch_id' => $this->branchId,
            'warehouse_id' => $this->warehouseId,
            'cash_register_id' => $this->cashRegisterId,
        ];
    }
}
