<?php

namespace App\Application\Commands\Purchases;

final class ExportPurchasesStockEntriesCommand
{
    public function __construct(
        public readonly int $companyId,
        public readonly mixed $branchId,
        public readonly ?string $entryType,
        public readonly ?string $reference,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
        public readonly mixed $warehouseId,
        public readonly bool $includeItems
    ) {
    }

    public static function fromInput(
        int $companyId,
        mixed $branchId,
        ?string $entryType,
        ?string $reference,
        ?string $dateFrom,
        ?string $dateTo,
        mixed $warehouseId,
        bool $includeItems
    ): self {
        return new self($companyId, $branchId, $entryType, $reference, $dateFrom, $dateTo, $warehouseId, $includeItems);
    }

    public function filters(): array
    {
        return [
            'entry_type' => $this->entryType,
            'reference' => $this->reference,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'warehouse_id' => $this->warehouseId,
        ];
    }
}
