<?php

namespace App\Application\UseCases\Sales;

use App\Application\Commands\Sales\VoidCommercialDocumentCommand;
use App\Services\Sales\Documents\SalesDocumentVoidService;

class VoidCommercialDocumentUseCase
{
    public function __construct(private SalesDocumentVoidService $service)
    {
    }

    public function execute(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        return $this->service->voidFromCommand(
            VoidCommercialDocumentCommand::fromInput($authUser, $companyId, $documentId, $payload)
        );
    }
}
