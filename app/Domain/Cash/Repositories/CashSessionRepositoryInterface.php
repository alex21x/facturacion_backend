<?php

namespace App\Domain\Cash\Repositories;

use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;
use Illuminate\Support\Collection;

interface CashSessionRepositoryInterface
{
    public function paginateSessions(int $companyId, ?int $cashRegisterId, ?string $status, int $page, int $limit): array;

    public function findCurrentOpenSession(int $companyId, ?int $cashRegisterId): ?CashSessionDetailDTO;

    public function findOpenSessionByRegister(int $companyId, int $cashRegisterId): ?CashSessionRecordDTO;

    public function createSession(array $payload): int;

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO;

    public function findSessionByIdAndCompany(int $sessionId, int $companyId): ?CashSessionRecordDTO;

    public function sumSessionMovementsByDirection(int $sessionId, array $movementTypes, array $documentRefTypes, array $excludedDocumentStatuses): float;

    public function listSessionSalesByPaymentMethod(int $sessionId, int $companyId, array $documentRefTypes, array $excludedDocumentStatuses): Collection;

    public function closeSession(int $sessionId, array $payload): void;
}
