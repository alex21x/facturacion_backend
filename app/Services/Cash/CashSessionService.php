<?php

namespace App\Services\Cash;

use App\Domain\Cash\Repositories\CashSessionRepositoryInterface;

class CashSessionService
{
    public function __construct(
        private CashSessionRepositoryInterface $repository
    ) {
    }

    public function paginateSessions(int $companyId, ?int $cashRegisterId, ?string $status, int $page, int $limit): array
    {
        return $this->repository->paginateSessions($companyId, $cashRegisterId, $status, $page, $limit);
    }

    public function findCurrentOpenSession(int $companyId, ?int $cashRegisterId): ?object
    {
        return $this->repository->findCurrentOpenSession($companyId, $cashRegisterId);
    }

    public function findOpenSessionByRegister(int $companyId, int $cashRegisterId): ?object
    {
        return $this->repository->findOpenSessionByRegister($companyId, $cashRegisterId);
    }

    public function createSession(array $payload): int
    {
        return $this->repository->createSession($payload);
    }

    public function findSessionById(int $sessionId): ?object
    {
        return $this->repository->findSessionById($sessionId);
    }

    public function findSessionByIdAndCompany(int $sessionId, int $companyId): ?object
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
            ->map(static function ($record): array {
                return [
                    'payment_method_id' => (int) $record->payment_method_id,
                    'payment_method_code' => (string) $record->payment_method_code,
                    'payment_method_name' => (string) $record->payment_method_name,
                    'document_count' => (int) $record->document_count,
                    'total_amount' => round((float) $record->total_amount, 4),
                ];
            })
            ->values()
            ->all();
    }

    public function closeSession(int $sessionId, int $closedByUserId, float $closingBalance, float $expectedBalance, ?string $notes): void
    {
        $this->repository->closeSession($sessionId, [
            'closed_at' => now(),
            'closed_by' => $closedByUserId,
            'closing_balance' => $closingBalance,
            'expected_balance' => $expectedBalance,
            'status' => 'CLOSED',
            'notes' => $notes,
        ]);
    }
}
