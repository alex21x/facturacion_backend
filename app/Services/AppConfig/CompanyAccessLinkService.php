<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanyAccessLinkRepository;
use Illuminate\Support\Str;

class CompanyAccessLinkService
{
    public function __construct(
        private CompanyAccessLinkRepository $companyAccessLinkRepository
    ) {
    }

    public function tableExists(string $schema, string $table): bool
    {
        return $this->companyAccessLinkRepository->tableExists($schema, $table);
    }

    public function getActiveByCompanyIds(array $companyIds)
    {
        return $this->companyAccessLinkRepository->getActiveByCompanyIds($companyIds);
    }

    public function existsByCompanyId(int $companyId): bool
    {
        return $this->companyAccessLinkRepository->existsByCompanyId($companyId);
    }

    public function ensureCompanyAccessLink(int $companyId, string $legalName, ?string $taxId, ?int $actorId): string
    {
        if (!$this->companyAccessLinkRepository->tableExists('appcfg', 'company_access_links')) {
            return '';
        }

        $existing = $this->companyAccessLinkRepository->findByCompanyId($companyId);
        if ($existing && !empty($existing->access_slug)) {
            $currentSlug = (string) $existing->access_slug;
            if (!$this->isSensitiveCompanyAccessSlug($currentSlug)) {
                return $currentSlug;
            }
        }

        $slug = $this->generateCompanyAccessSlug($companyId, $legalName, $taxId);

        $this->companyAccessLinkRepository->updateOrInsertByCompanyId(
            $companyId,
            [
                'access_slug' => $slug,
                'is_active' => true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $slug;
    }

    private function generateCompanyAccessSlug(int $companyId, string $legalName, ?string $taxId): string
    {
        $hashSeed = hash('sha256', 'company-link|' . $companyId . '|' . (string) config('app.key'));
        $base = 'emp-' . strtolower(substr($hashSeed, 0, 12));
        $candidate = $base;
        $suffix = 1;

        while ($this->companyAccessLinkRepository->slugExistsForOtherCompany($candidate, $companyId)) {
            $suffix++;
            $candidate = $base . '-' . $suffix;
        }

        return $candidate;
    }

    private function isSensitiveCompanyAccessSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return true;
        }

        return Str::startsWith($normalized, 'ruc-');
    }
}
