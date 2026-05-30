<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanyRateLimitRepository;

class CompanyRateLimitCommandService
{
    public function __construct(
        private CompanyRateLimitRepository $companyRateLimitRepository
    ) {
    }

    public function updateCompanyRateLimit(int $companyId, array $payload, ?int $updatedBy): void
    {
        $this->companyRateLimitRepository->upsertCompanyRateLimit($companyId, $payload, $updatedBy);
    }

    public function updateCompanyRateLimitBulk(array $companyIds, array $payload, ?int $updatedBy): void
    {
        $normalizedCompanyIds = array_values(array_unique(array_map(static fn ($id) => (int) $id, $companyIds)));
        $this->companyRateLimitRepository->upsertCompanyRateLimitsBatch($normalizedCompanyIds, $payload, $updatedBy);
    }
}
