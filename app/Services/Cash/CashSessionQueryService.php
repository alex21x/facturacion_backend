<?php

namespace App\Services\Cash;

use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;
use App\Domain\Cash\Repositories\CashSessionRepositoryInterface;
use App\Services\Cash\Presenters\CashSessionPresenter;

class CashSessionQueryService
{
    public function __construct(
        private CashSessionRepositoryInterface $repository,
        private CashSessionPresenter $presenter
    ) {
    }

    public function paginateSessions(int $companyId, ?int $cashRegisterId, ?string $status, int $page, int $limit): array
    {
        return $this->repository->paginateSessions($companyId, $cashRegisterId, $status, $page, $limit);
    }

    public function findCurrentOpenSession(int $companyId, ?int $cashRegisterId): ?CashSessionDetailDTO
    {
        return $this->repository->findCurrentOpenSession($companyId, $cashRegisterId);
    }

    public function findOpenSessionByRegister(int $companyId, int $cashRegisterId): ?CashSessionRecordDTO
    {
        return $this->repository->findOpenSessionByRegister($companyId, $cashRegisterId);
    }

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO
    {
        return $this->repository->findSessionById($sessionId);
    }

    public function findSessionByIdAndCompany(int $sessionId, int $companyId): ?CashSessionRecordDTO
    {
        return $this->repository->findSessionByIdAndCompany($sessionId, $companyId);
    }

    public function sumSessionIncome(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return $this->repository->sumSessionMovementsByDirection($sessionId, ['IN', 'INCOME'], $documentRefTypes, $excludedDocumentStatuses);
    }

    public function sumSessionExpense(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return $this->repository->sumSessionMovementsByDirection($sessionId, ['OUT', 'EXPENSE'], $documentRefTypes, $excludedDocumentStatuses);
    }

    public function listSessionSalesByPaymentMethod(int $sessionId, int $companyId, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->repository->listSessionSalesByPaymentMethod($sessionId, $companyId, $documentRefTypes, $excludedDocumentStatuses)
            ->map(fn ($record): array => $this->presenter->presentSalesByPaymentMethod($record))
            ->values()
            ->all();
    }
}
