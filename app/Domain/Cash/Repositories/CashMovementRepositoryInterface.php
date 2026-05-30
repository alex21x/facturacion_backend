<?php

namespace App\Domain\Cash\Repositories;

use App\Application\DTOs\Cash\CashMovementDTO;
use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;
use App\Application\DTOs\Cash\CashSessionScopeDTO;
use Illuminate\Support\Collection;

interface CashMovementRepositoryInterface
{
    public function listMovements(int $companyId, ?int $sessionId, ?int $cashRegisterId, int $limit, array $documentRefTypes, array $excludedDocumentStatuses): Collection;

    public function findMovementById(int $movementId): ?CashMovementDTO;

    public function createMovement(array $payload): int;

    public function findSessionScopeForCommercialDocuments(int $companyId, int $sessionId): ?CashSessionScopeDTO;

    public function listSessionCommercialDocuments(int $companyId, int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): Collection;

    public function listSyncSessionCommercialDocuments(int $companyId, int $sessionId, array $documentKinds): Collection;

    public function listDocumentItems(int $documentId, int $companyId): Collection;

    public function findSessionDetail(int $companyId, int $sessionId): ?CashSessionDetailDTO;

    public function upsertSessionCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): void;

    public function insertCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): void;

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO;

    public function recalcExpectedBalance(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): void;
}
