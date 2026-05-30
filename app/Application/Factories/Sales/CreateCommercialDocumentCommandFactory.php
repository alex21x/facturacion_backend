<?php

namespace App\Application\Factories\Sales;

use App\Application\Commands\Sales\CreateCommercialDocumentCommand;

class CreateCommercialDocumentCommandFactory
{
    public function fromPreparedPayload(
        object $authUser,
        array $preparedPayload,
        int $companyId,
        ?int $branchId,
        ?int $warehouseId,
        ?int $cashRegisterId
    ): CreateCommercialDocumentCommand {
        return new CreateCommercialDocumentCommand(
            $authUser,
            $preparedPayload,
            $companyId,
            $branchId,
            $warehouseId,
            $cashRegisterId
        );
    }
}
