<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanyProfileRepository;

class CompanyProfileCommandService
{
    public function __construct(private CompanyProfileRepository $repository)
    {
    }

    public function updateCompanyBasic(int $companyId, array $updates): void
    {
        $this->repository->updateCompanyBasic($companyId, $updates);
    }

    public function updateCompanySettings(int $companyId, array $updates): int
    {
        return $this->repository->updateCompanySettings($companyId, $updates);
    }

    public function upsertCompanySettings(int $companyId, array $updates, bool $hasCreatedAt): void
    {
        $affectedRows = $this->repository->updateCompanySettings($companyId, $updates);
        if ($affectedRows > 0) {
            return;
        }

        $insertValues = $hasCreatedAt
            ? array_merge(['created_at' => now()], $updates)
            : $updates;

        $this->repository->insertCompanySettings($companyId, $insertValues);
    }
}
