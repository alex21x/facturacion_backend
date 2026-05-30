<?php

namespace App\Services\AppConfig;

class CompanyAccessLinkService
{
    public function __construct(
        private CompanyAccessLinkQueryService $queryService,
        private CompanyAccessLinkCommandService $commandService
    ) {
    }

    public function tableExists(string $schema, string $table): bool
    {
        return $this->queryService->tableExists($schema, $table);
    }

    public function getActiveByCompanyIds(array $companyIds)
    {
        return $this->queryService->getActiveByCompanyIds($companyIds);
    }

    public function getByCompanyIds(array $companyIds)
    {
        return $this->queryService->getByCompanyIds($companyIds);
    }

    public function existsByCompanyId(int $companyId): bool
    {
        return $this->queryService->existsByCompanyId($companyId);
    }

    public function ensureCompanyAccessLink(int $companyId, string $legalName, ?string $taxId, ?int $actorId): string
    {
        return $this->commandService->ensureCompanyAccessLink($companyId, $legalName, $taxId, $actorId);
    }
}
