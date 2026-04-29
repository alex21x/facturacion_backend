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
