<?php

namespace App\Services\Cash;

use App\Application\DTOs\Cash\CashMovementDTO;
use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;
use App\Application\DTOs\Cash\CashSessionScopeDTO;

class CashMovementService
{
    public function __construct(
        private CashMovementQueryService $queryService,
        private CashMovementCommandService $commandService
    ) {
    }

    public function listMovements(int $companyId, ?int $sessionId, ?int $cashRegisterId, int $limit, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->queryService->listMovements(
            $companyId,
            $sessionId,
            $cashRegisterId,
            $limit,
            $documentRefTypes,
            $excludedDocumentStatuses
        );
    }

    public function findMovementById(int $movementId): ?CashMovementDTO
    {
        return $this->queryService->findMovementById($movementId);
    }

    public function createMovement(array $payload): int
    {
        return $this->commandService->createMovement($payload);
    }

    public function updateMovementById(int $companyId, int $movementId, array $changes): void
    {
        $this->commandService->updateMovementById($companyId, $movementId, $changes);
    }

    public function findSessionScopeForCommercialDocuments(int $companyId, int $sessionId): ?CashSessionScopeDTO
    {
        return $this->queryService->findSessionScopeForCommercialDocuments($companyId, $sessionId);
    }

    public function listSessionCommercialDocuments(int $companyId, int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->queryService->listSessionCommercialDocuments($companyId, $sessionId, $documentRefTypes, $excludedDocumentStatuses);
    }

    public function listSyncSessionCommercialDocuments(int $companyId, int $sessionId, array $documentKinds): array
    {
        return $this->queryService->listSyncSessionCommercialDocuments($companyId, $sessionId, $documentKinds);
    }

    public function listDocumentItems(int $documentId, int $companyId): array
    {
        return $this->queryService->listDocumentItems($documentId, $companyId);
    }

    public function findSessionDetail(int $companyId, int $sessionId): ?CashSessionDetailDTO
    {
        return $this->queryService->findSessionDetail($companyId, $sessionId);
    }

    public function upsertSessionCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): void
    {
        $this->commandService->upsertSessionCommercialDocumentMovement($companyId, $sessionId, $document, $session);
    }

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO
    {
        return $this->queryService->findSessionById($sessionId);
    }

    public function recalcExpectedBalance(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): void
    {
        $this->commandService->recalcExpectedBalance($sessionId, $documentRefTypes, $excludedDocumentStatuses);
    }
}
