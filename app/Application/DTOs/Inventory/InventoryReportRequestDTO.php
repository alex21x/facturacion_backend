<?php

namespace App\Application\DTOs\Inventory;

final class InventoryReportRequestDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $company_id,
        public readonly ?int $branch_id,
        public readonly int $requested_by,
        public readonly string $report_type,
        public readonly ?string $filters_json,
        public readonly string $status,
        public readonly ?string $result_json,
        public readonly ?string $error_message,
        public readonly ?string $requested_at,
        public readonly ?string $started_at,
        public readonly ?string $finished_at
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            company_id: (int) $row->company_id,
            branch_id: isset($row->branch_id) ? (int) $row->branch_id : null,
            requested_by: (int) $row->requested_by,
            report_type: (string) $row->report_type,
            filters_json: isset($row->filters_json) ? (string) $row->filters_json : null,
            status: (string) $row->status,
            result_json: isset($row->result_json) ? (string) $row->result_json : null,
            error_message: isset($row->error_message) ? (string) $row->error_message : null,
            requested_at: isset($row->requested_at) ? (string) $row->requested_at : null,
            started_at: isset($row->started_at) ? (string) $row->started_at : null,
            finished_at: isset($row->finished_at) ? (string) $row->finished_at : null,
        );
    }
}
