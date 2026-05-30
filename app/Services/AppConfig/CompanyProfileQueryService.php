<?php

namespace App\Services\AppConfig;

use App\Application\DTOs\AppConfig\CompanyBridgePayloadDTO;
use App\Application\DTOs\AppConfig\CompanyProfileDTO;
use App\Application\DTOs\AppConfig\CompanySettingsDTO;
use App\Infrastructure\Repositories\AppConfig\CompanyProfileRepository;

class CompanyProfileQueryService
{
    public function __construct(private CompanyProfileRepository $repository)
    {
    }

    public function companyExists(int $companyId): bool
    {
        return $this->repository->companyExistsById($companyId);
    }

    public function findCompanyProfileRow(int $companyId): ?CompanyProfileDTO
    {
        return $this->repository->findCompanyProfileRow($companyId);
    }

    public function findCompanyForBridgePayload(int $companyId): ?CompanyBridgePayloadDTO
    {
        return $this->repository->findCompanyForBridgePayload($companyId);
    }

    public function findLatestSettings(int $companyId, bool $hasLogoPath, bool $hasUpdatedAt, bool $hasCreatedAt): ?CompanySettingsDTO
    {
        return $this->repository->findLatestCompanySettings($companyId, $hasLogoPath, $hasUpdatedAt, $hasCreatedAt);
    }

    public function findBridgeSettings(int $companyId): ?CompanySettingsDTO
    {
        return $this->repository->findBridgeSettings($companyId);
    }
}
