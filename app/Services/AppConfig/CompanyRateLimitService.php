<?php

namespace App\Services\AppConfig;

class CompanyRateLimitService
{
    public function __construct(
        private CompanyRateLimitQueryService $queryService,
        private CompanyRateLimitCommandService $commandService
    ) {
    }

    public function hasTable(): bool
    {
        return $this->queryService->hasTable();
    }

    public function companyExists(int $companyId): bool
    {
        return $this->queryService->companyExists($companyId);
    }

    public function existingCompanyIds(array $companyIds): array
    {
        return $this->queryService->existingCompanyIds($companyIds);
    }

    public function listMatrixRows(
        int $systemCompanyId,
        int $defaultRead,
        int $defaultWrite,
        int $defaultReports
    ): array {
        return $this->queryService->listMatrixRows($systemCompanyId, $defaultRead, $defaultWrite, $defaultReports);
    }

    public function updateCompanyRateLimit(int $companyId, array $payload, ?int $updatedBy): void
    {
        $this->commandService->updateCompanyRateLimit($companyId, $payload, $updatedBy);
    }

    public function updateCompanyRateLimitBulk(array $companyIds, array $payload, ?int $updatedBy): void
    {
        $this->commandService->updateCompanyRateLimitBulk($companyIds, $payload, $updatedBy);
    }
}
