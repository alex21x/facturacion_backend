<?php

namespace App\Application\UseCases\Sales;

use App\Application\Commands\Sales\UpdateCommercialDocumentDraftCommand;
use App\Services\Sales\Documents\SalesDocumentUpdateService;

class UpdateCommercialDocumentDraftUseCase
{
    public function __construct(private SalesDocumentUpdateService $service)
    {
    }

    public function execute(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        return $this->service->updateDraftFromCommand(
            UpdateCommercialDocumentDraftCommand::fromInput($authUser, $companyId, $documentId, $payload)
        );
    }
}
