<?php

namespace App\Application\UseCases\Sales;

use App\Application\Commands\Sales\CreateCommercialDocumentCommand;
use App\Services\Sales\Documents\SalesDocumentCreationService;

class CreateCommercialDocumentUseCase
{
    public function __construct(private SalesDocumentCreationService $service)
    {
    }

    public function execute(object $authUser, array $payload, int $companyId, ?int $branchId, ?int $warehouseId, ?int $cashRegisterId): array
    {
        return $this->service->createFromCommand(
            CreateCommercialDocumentCommand::fromInput(
                $authUser,
                $payload,
                $companyId,
                $branchId,
                $warehouseId,
                $cashRegisterId
            )
        );
    }
}
