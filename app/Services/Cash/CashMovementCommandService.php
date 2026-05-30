<?php

namespace App\Services\Cash;

use App\Domain\Cash\Repositories\CashMovementRepositoryInterface;

class CashMovementCommandService
{
    public function __construct(
        private CashMovementRepositoryInterface $repository
    ) {
    }

    public function createMovement(array $payload): int
    {
        return $this->repository->createMovement($payload);
    }

    public function upsertSessionCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): void
    {
        $this->repository->upsertSessionCommercialDocumentMovement($companyId, $sessionId, $document, $session);
    }

    public function recalcExpectedBalance(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): void
    {
        $this->repository->recalcExpectedBalance($sessionId, $documentRefTypes, $excludedDocumentStatuses);
    }
}
