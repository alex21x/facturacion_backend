<?php

namespace App\Services\Sales\Documents;

use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;

class SalesDocumentSeriesService
{
    public function __construct(private CommercialDocumentRepositoryInterface $documentRepository)
    {
    }

    public function reserveNextNumber(
        int $companyId,
        string $documentKind,
        string $series,
        ?int $branchId,
        ?int $warehouseId,
        int $authUserId,
        ?int $documentKindId = null
    ): int {
        $seriesRow = $this->documentRepository->getSeriesNumber(
            $companyId,
            $documentKind,
            $series,
            $branchId,
            $warehouseId,
            $documentKindId
        );

        // Fallback: if the user has no warehouse assigned (warehouseId = null) and no series
        // was found with warehouse_id IS NULL, try to find the series without warehouse restriction.
        // This allows users without a warehouse assignment to use series configured for any warehouse
        // within their branch.
        if (!$seriesRow && $warehouseId === null) {
            $seriesRow = $this->documentRepository->getSeriesNumberAnyWarehouse(
                $companyId,
                $documentKind,
                $series,
                $branchId,
                $documentKindId
            );
        }

        if (!$seriesRow) {
            throw new SalesDocumentException(
                'No hay serie activa para ' . $documentKind . ' en la sucursal/almacen seleccionado. Configurala en Maestros > Series.'
            );
        }

        $nextNumber = ((int) $seriesRow->current_number) + 1;
        $this->documentRepository->incrementSeriesNumber((int) $seriesRow->id, $authUserId);

        return $nextNumber;
    }
}
