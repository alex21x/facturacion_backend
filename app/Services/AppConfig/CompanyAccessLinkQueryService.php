<?php

namespace App\Services\AppConfig;

use App\Application\DTOs\AppConfig\CompanyAccessLinkDTO;
use App\Infrastructure\Repositories\AppConfig\CompanyAccessLinkRepository;

class CompanyAccessLinkQueryService
{
    private ?bool $companyAccessLinksTableExists = null;

    public function __construct(
        private CompanyAccessLinkRepository $companyAccessLinkRepository
    ) {
    }

    public function tableExists(string $schema, string $table): bool
    {
        if ($schema === 'appcfg' && $table === 'company_access_links') {
            if ($this->companyAccessLinksTableExists === null) {
                $this->companyAccessLinksTableExists = $this->companyAccessLinkRepository->tableExists($schema, $table);
            }

            return $this->companyAccessLinksTableExists;
        }

        return $this->companyAccessLinkRepository->tableExists($schema, $table);
    }

    public function getActiveByCompanyIds(array $companyIds)
    {
        return $this->companyAccessLinkRepository->getActiveByCompanyIds($companyIds);
    }

    public function getByCompanyIds(array $companyIds)
    {
        return $this->companyAccessLinkRepository->getByCompanyIds($companyIds);
    }

    public function existsByCompanyId(int $companyId): bool
    {
        return $this->companyAccessLinkRepository->existsByCompanyId($companyId);
    }

    public function findByCompanyId(int $companyId): ?CompanyAccessLinkDTO
    {
        return $this->companyAccessLinkRepository->findByCompanyId($companyId);
    }
}
