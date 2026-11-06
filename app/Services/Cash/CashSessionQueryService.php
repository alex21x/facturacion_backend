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

    public function sumSessionCashIncome(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return $this->repository->sumSessionMovementsByDirection($sessionId, ['IN', 'INCOME'], $documentRefTypes, $excludedDocumentStatuses, true);
    }

    public function sumSessionExpense(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return $this->repository->sumSessionMovementsByDirection($sessionId, ['OUT', 'EXPENSE'], $documentRefTypes, $excludedDocumentStatuses);
    }

    public function sumSessionCashExpense(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return $this->repository->sumSessionMovementsByDirection($sessionId, ['OUT', 'EXPENSE'], $documentRefTypes, $excludedDocumentStatuses, true);
    }

    /**
     * @return array{total_in: float, total_out: float, cash_total_in: float, cash_total_out: float, expected_balance: float}
     */
    public function summarizeSessionBalance(
        int $sessionId,
        float $openingBalance,
        array $documentRefTypes,
        array $excludedDocumentStatuses
    ): array {
        $totalIn = $this->sumSessionIncome($sessionId, $documentRefTypes, $excludedDocumentStatuses);
        $totalOut = $this->sumSessionExpense($sessionId, $documentRefTypes, $excludedDocumentStatuses);
        $cashTotalIn = $this->sumSessionCashIncome($sessionId, $documentRefTypes, $excludedDocumentStatuses);
        $cashTotalOut = $this->sumSessionCashExpense($sessionId, $documentRefTypes, $excludedDocumentStatuses);

        return [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'cash_total_in' => $cashTotalIn,
            'cash_total_out' => $cashTotalOut,
            'expected_balance' => round($openingBalance + $cashTotalIn - $cashTotalOut, 4),
        ];
    }

    public function listSessionSalesByPaymentMethod(int $sessionId, int $companyId, array $documentRefTypes, array $excludedDocumentStatuses): array
    {
        return $this->repository->listSessionSalesByPaymentMethod($sessionId, $companyId, $documentRefTypes, $excludedDocumentStatuses)
            ->map(fn ($record): array => $this->presenter->presentSalesByPaymentMethod($record))
            ->values()
            ->all();
    }
}
