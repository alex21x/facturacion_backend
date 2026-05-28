<?php

namespace App\Domain\Cash\Repositories;

use Illuminate\Support\Collection;

interface CashMovementRepositoryInterface
{
    public function listMovements(int $companyId, ?int $sessionId, ?int $cashRegisterId, int $limit, array $documentRefTypes, array $excludedDocumentStatuses): Collection;

    public function findMovementById(int $movementId): ?object;

    public function createMovement(array $payload): int;

    public function findSessionScopeForCommercialDocuments(int $companyId, int $sessionId): ?object;

    public function listSessionCommercialDocuments(int $companyId, int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): Collection;

    public function listSyncSessionCommercialDocuments(int $companyId, int $sessionId, array $documentKinds): Collection;

    public function listDocumentItems(int $documentId, int $companyId): Collection;

    public function findSessionDetail(int $companyId, int $sessionId): ?object;

    public function upsertSessionCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): void;

    public function insertCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): void;

    public function findSessionById(int $sessionId): ?object;

    public function recalcExpectedBalance(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): void;
}
