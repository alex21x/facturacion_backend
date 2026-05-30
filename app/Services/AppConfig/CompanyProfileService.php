<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanyProfileRepository;

class CompanyProfileService
{
    public function __construct(private CompanyProfileRepository $repository)
    {
    }

    public function companyExists(int $companyId): bool
    {
        return $this->repository->companyExistsById($companyId);
    }

    public function findCompanyProfileRow(int $companyId): ?object
    {
        return $this->repository->findCompanyProfileRow($companyId);
    }

    public function findCompanyForBridgePayload(int $companyId): ?object
    {
        return $this->repository->findCompanyForBridgePayload($companyId);
    }

    public function findLatestSettings(int $companyId, bool $hasLogoPath, bool $hasUpdatedAt, bool $hasCreatedAt): ?object
    {
        return $this->repository->findLatestCompanySettings($companyId, $hasLogoPath, $hasUpdatedAt, $hasCreatedAt);
    }

    public function findBridgeSettings(int $companyId): ?object
    {
        return $this->repository->findBridgeSettings($companyId);
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
