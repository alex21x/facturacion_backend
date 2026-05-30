<?php

namespace App\Services\AppConfig;

use App\Application\DTOs\AppConfig\CompanyBridgePayloadDTO;
use App\Application\DTOs\AppConfig\CompanyProfileDTO;
use App\Application\DTOs\AppConfig\CompanySettingsDTO;

class CompanyProfileService
{
    public function __construct(
        private CompanyProfileQueryService $queryService,
        private CompanyProfileCommandService $commandService
    )
    {
    }

    public function companyExists(int $companyId): bool
    {
        return $this->queryService->companyExists($companyId);
    }

    public function findCompanyProfileRow(int $companyId): ?CompanyProfileDTO
    {
        return $this->queryService->findCompanyProfileRow($companyId);
    }

    public function findCompanyForBridgePayload(int $companyId): ?CompanyBridgePayloadDTO
    {
        return $this->queryService->findCompanyForBridgePayload($companyId);
    }

    public function findLatestSettings(int $companyId, bool $hasLogoPath, bool $hasUpdatedAt, bool $hasCreatedAt): ?CompanySettingsDTO
    {
        return $this->queryService->findLatestSettings($companyId, $hasLogoPath, $hasUpdatedAt, $hasCreatedAt);
    }

    public function findBridgeSettings(int $companyId): ?CompanySettingsDTO
    {
        return $this->queryService->findBridgeSettings($companyId);
    }

    public function updateCompanyBasic(int $companyId, array $updates): void
    {
        $this->commandService->updateCompanyBasic($companyId, $updates);
    }

    public function updateCompanySettings(int $companyId, array $updates): int
    {
        return $this->commandService->updateCompanySettings($companyId, $updates);
    }

    public function upsertCompanySettings(int $companyId, array $updates, bool $hasCreatedAt): void
    {
        $this->commandService->upsertCompanySettings($companyId, $updates, $hasCreatedAt);
    }
}
