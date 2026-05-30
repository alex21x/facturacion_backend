<?php

namespace App\Services\Cash;

use App\Domain\Cash\Repositories\CashSessionRepositoryInterface;

class CashSessionCommandService
{
    public function __construct(
        private CashSessionRepositoryInterface $repository
    ) {
    }

    public function createSession(array $payload): int
    {
        return $this->repository->createSession($payload);
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
