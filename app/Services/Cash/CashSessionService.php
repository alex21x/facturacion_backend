<?php

namespace App\Services\Cash;

use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;

class CashSessionService
{
    public function __construct(
        private CashSessionQueryService $queryService,
        private CashSessionCommandService $commandService
    ) {
    }

    public function paginateSessions(int $companyId, ?int $cashRegisterId, ?string $status, int $page, int $limit): array
    {
        return $this->queryService->paginateSessions($companyId, $cashRegisterId, $status, $page, $limit);
    }

    public function findCurrentOpenSession(int $companyId, ?int $cashRegisterId): ?CashSessionDetailDTO
    {
        return $this->queryService->findCurrentOpenSession($companyId, $cashRegisterId);
    }

    public function findOpenSessionByRegister(int $companyId, int $cashRegisterId): ?CashSessionRecordDTO
    {
        return $this->queryService->findOpenSessionByRegister($companyId, $cashRegisterId);
    }

    public function createSession(array $payload): int
    {
        return $this->commandService->createSession($payload);
    }

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO
    {
        return $this->queryService->findSessionById($sessionId);
    }

    public function findSessionByIdAndCompany(int $sessionId, int $companyId): ?CashSessionRecordDTO
    {
        return $this->queryService->findSessionByIdAndCompany($sessionId, $companyId);
    }

    public function sumSessionIncome(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return $this->queryService->sumSessionIncome($sessionId, $documentRefTypes, $excludedDocumentStatuses);
    }

    public function sumSessionExpense(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return $this->queryService->sumSessionExpense($sessionId, $documentRefTypes, $excludedDocumentStatuses);
    }

    public function listSessionSalesByPaymentMethod(int $sessionId, int $companyId, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->queryService->listSessionSalesByPaymentMethod($sessionId, $companyId, $documentRefTypes, $excludedDocumentStatuses);
    }

    public function closeSession(int $sessionId, int $closedByUserId, float $closingBalance, float $expectedBalance, ?string $notes): void
    {
        $this->commandService->closeSession($sessionId, $closedByUserId, $closingBalance, $expectedBalance, $notes);
    }
}
