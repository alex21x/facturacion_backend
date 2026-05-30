<?php

namespace App\Services\Cash;

use App\Application\DTOs\Cash\CashMovementDTO;
use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;
use App\Application\DTOs\Cash\CashSessionScopeDTO;
use App\Domain\Cash\Repositories\CashMovementRepositoryInterface;
use App\Services\Cash\Presenters\CashMovementPresenter;

class CashMovementQueryService
{
    public function __construct(
        private CashMovementRepositoryInterface $repository,
        private CashMovementPresenter $presenter
    ) {
    }

    public function listMovements(int $companyId, ?int $sessionId, ?int $cashRegisterId, int $limit, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->repository->listMovements($companyId, $sessionId, $cashRegisterId, $limit, $documentRefTypes, $excludedDocumentStatuses)
            ->map(fn ($movement): array => $this->presenter->presentMovement($movement))
            ->values()
            ->all();
    }

    public function findMovementById(int $movementId): ?CashMovementDTO
    {
        return $this->repository->findMovementById($movementId);
    }

    public function findSessionScopeForCommercialDocuments(int $companyId, int $sessionId): ?CashSessionScopeDTO
    {
        return $this->repository->findSessionScopeForCommercialDocuments($companyId, $sessionId);
    }

    public function listSessionCommercialDocuments(int $companyId, int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->repository->listSessionCommercialDocuments($companyId, $sessionId, $documentRefTypes, $excludedDocumentStatuses)->all();
    }

    public function listSyncSessionCommercialDocuments(int $companyId, int $sessionId, array $documentKinds): array
    {
        return $this->repository->listSyncSessionCommercialDocuments($companyId, $sessionId, $documentKinds)->all();
    }

    public function listDocumentItems(int $documentId, int $companyId): array
    {
        return $this->repository->listDocumentItems($documentId, $companyId)->all();
    }

    public function findSessionDetail(int $companyId, int $sessionId): ?CashSessionDetailDTO
    {
        return $this->repository->findSessionDetail($companyId, $sessionId);
    }

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO
    {
        return $this->repository->findSessionById($sessionId);
    }
}
