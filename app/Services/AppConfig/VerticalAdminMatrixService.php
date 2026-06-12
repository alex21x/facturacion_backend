<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\VerticalAdminMatrixRepository;
use Illuminate\Support\Facades\Cache;

class VerticalAdminMatrixService
{
    private const TABLE_EXISTS_CACHE_TTL_SECONDS = 300;
    private const ADMIN_MATRIX_CACHE_TTL_SECONDS = 20;

    private array $tableExistsCache = [];
    private array $adminMatrixCache = [];

    public function __construct(
        private VerticalAdminMatrixRepository $verticalAdminMatrixRepository,
        private CompanyAccessLinkService $companyAccessLinkService
    ) {
    }

    public function hasRequiredTables(): bool
    {
        return $this->tableExistsCached('appcfg', 'verticals')
            && $this->tableExistsCached('appcfg', 'company_verticals');
    }

    public function buildAdminMatrix(int $systemCompanyId): array
    {
        if (array_key_exists($systemCompanyId, $this->adminMatrixCache)) {
            return $this->adminMatrixCache[$systemCompanyId];
        }

        $cacheKey = 'vertical_admin_matrix:v1:system_company:' . $systemCompanyId;

        $matrix = Cache::remember($cacheKey, self::ADMIN_MATRIX_CACHE_TTL_SECONDS, function () use ($systemCompanyId) {
            $verticals = $this->verticalAdminMatrixRepository->listActiveVerticals();
            $companies = $this->verticalAdminMatrixRepository->listNonSystemCompanies($systemCompanyId);
            $companyIds = $companies->pluck('id')->map(fn ($id) => (int) $id)->all();

            $existingAccessLinks = collect();
            if ($this->companyAccessLinkService->tableExists('appcfg', 'company_access_links')) {
                $existingAccessLinks = $this->companyAccessLinkService->getByCompanyIds($companyIds);
            }

            foreach ($companies as $company) {
                $companyId = (int) $company->id;
                if (!$existingAccessLinks->has($companyId)) {
                    $this->companyAccessLinkService->ensureCompanyAccessLink(
                        $companyId,
                        (string) ($company->legal_name ?? ''),
                        $company->tax_id !== null ? (string) $company->tax_id : null,
                        null
                    );
                }
            }

            $accessLinksByCompany = collect();
            if ($this->companyAccessLinkService->tableExists('appcfg', 'company_access_links')) {
                $accessLinksByCompany = $this->companyAccessLinkService->getActiveByCompanyIds($companyIds);
            }

            $assignments = $this->verticalAdminMatrixRepository->listAssignmentsByCompanyIds($companyIds);
            $byCompany = [];
            foreach ($assignments as $row) {
                $companyId = (int) $row->company_id;
                if (!array_key_exists($companyId, $byCompany)) {
                    $byCompany[$companyId] = [];
                }

                $byCompany[$companyId][] = [
                    'vertical_id' => (int) $row->vertical_id,
                    'vertical_code' => (string) $row->vertical_code,
                    'vertical_name' => (string) $row->vertical_name,
                    'is_enabled' => (int) $row->status === 1,
                    'is_primary' => (bool) $row->is_primary,
                    'effective_from' => $row->effective_from,
                    'effective_to' => $row->effective_to,
                ];
            }

            $adminUsersByCompany = collect();
            $adminUsersRaw = $this->verticalAdminMatrixRepository->listAdminUsersByCompanyIds($companyIds);
            foreach ($adminUsersRaw as $au) {
                $cid = (int) $au->company_id;
                if (!$adminUsersByCompany->has($cid)) {
                    $adminUsersByCompany->put($cid, $au);
                }
            }

            $missingAdminCompanyIds = array_values(array_diff(
                $companyIds,
                $adminUsersByCompany->keys()->map(fn ($id) => (int) $id)->all()
            ));

            if (!empty($missingAdminCompanyIds)) {
                $fallbackUsers = $this->verticalAdminMatrixRepository->listFallbackUsersByCompanyIds($missingAdminCompanyIds);
                foreach ($fallbackUsers as $fu) {
                    $cid = (int) $fu->company_id;
                    if (!$adminUsersByCompany->has($cid)) {
                        $adminUsersByCompany->put($cid, $fu);
                    }
                }
            }

            $companyRows = $companies->map(function ($company) use ($byCompany, $accessLinksByCompany, $adminUsersByCompany) {
                $companyId = (int) $company->id;
                $companyAssignments = $byCompany[$companyId] ?? [];
                $accessLink = $accessLinksByCompany->get($companyId);
                $accessSlug = $accessLink ? (string) $accessLink->access_slug : null;
                $adminUser = $adminUsersByCompany->get($companyId);

                $active = null;
                foreach ($companyAssignments as $assignment) {
                    if ($assignment['is_enabled'] && $assignment['is_primary']) {
                        $active = $assignment;
                        break;
                    }
                }

                return [
                    'company_id' => $companyId,
                    'tax_id' => $company->tax_id,
                    'legal_name' => $company->legal_name,
                    'trade_name' => $company->trade_name,
                    'company_status' => (int) $company->status,
                    'issued_documents_count' => (int) ($company->issued_documents_count ?? 0),
                    'issued_last_at' => $company->issued_last_at !== null ? (string) $company->issued_last_at : null,
                    'active_vertical_code' => $active['vertical_code'] ?? null,
                    'active_vertical_name' => $active['vertical_name'] ?? null,
                    'access_slug' => $accessSlug,
                    'access_link_active' => $accessLink !== null,
                    'assignments' => $companyAssignments,
                    'admin_username' => $adminUser ? $adminUser->username : null,
                    'admin_email' => $adminUser ? $adminUser->email : null,
                ];
            })->values()->all();

            return [
                'verticals' => $verticals,
                'companies' => $companyRows,
            ];
        });

        $this->adminMatrixCache[$systemCompanyId] = $matrix;

        return $matrix;
    }

    public function getCompanyVerticalSettings(int $companyId): array
    {
        $verticals = $this->verticalAdminMatrixRepository->listCompanyVerticalSettingsRows($companyId);

        $active = $verticals->first(function ($row) {
            return (bool) $row->is_primary === true;
        });

        return [
            'active_vertical' => $active ? [
                'id' => (int) $active->id,
                'code' => (string) $active->code,
                'name' => (string) $active->name,
                'description' => $active->description,
                'effective_from' => $active->effective_from,
                'effective_to' => $active->effective_to,
            ] : null,
            'verticals' => $verticals,
        ];
    }

    public function updateCompanyVerticalSettings(int $companyId, int $verticalId, string $effectiveFrom, int $updatedBy): void
    {
        $this->verticalAdminMatrixRepository->runInTransaction(function () use ($companyId, $verticalId, $effectiveFrom, $updatedBy) {
            $this->verticalAdminMatrixRepository->clearEnabledPrimaryVerticals($companyId, $updatedBy);
            $this->verticalAdminMatrixRepository->upsertCompanyVertical(
                $companyId,
                $verticalId,
                true,
                true,
                $effectiveFrom,
                $updatedBy
            );
        });

        $this->resetCachedAdminData();
    }

    public function existingCompanyIds(array $companyIds): array
    {
        return $this->verticalAdminMatrixRepository->existingCompanyIds($companyIds);
    }

    public function resolveVerticalByCode(string $verticalCode): ?array
    {
        $row = $this->verticalAdminMatrixRepository->findActiveVerticalByCode($verticalCode);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ];
    }

    public function applyAdminMatrixUpdate(
        int $companyId,
        int $verticalId,
        bool $isEnabled,
        bool $makePrimary,
        string $effectiveFrom,
        int $updatedBy,
        bool $resetCache = true
    ): void {
        $this->verticalAdminMatrixRepository->runInTransaction(function () use (
            $companyId,
            $verticalId,
            $isEnabled,
            $makePrimary,
            $effectiveFrom,
            $updatedBy
        ) {
            if ($isEnabled) {
                $this->verticalAdminMatrixRepository->updateCompanyStatus($companyId, 1);

                if ($makePrimary) {
                    $this->verticalAdminMatrixRepository->clearEnabledPrimaryVerticals($companyId, $updatedBy);
                }

                $this->verticalAdminMatrixRepository->upsertCompanyVertical(
                    $companyId,
                    $verticalId,
                    true,
                    $makePrimary,
                    $effectiveFrom,
                    $updatedBy
                );

                $this->ensurePrimaryForEnabledVerticals($companyId, $updatedBy);
                return;
            }

            $this->verticalAdminMatrixRepository->updateCompanyStatus($companyId, 0);
            $this->verticalAdminMatrixRepository->disableEnabledCompanyVerticals($companyId, $updatedBy);

            $companyUserIds = $this->verticalAdminMatrixRepository->listCompanyUserIds($companyId);
            $this->verticalAdminMatrixRepository->revokeActiveRefreshTokensByUserIds($companyUserIds);

            $this->ensurePrimaryForEnabledVerticals($companyId, $updatedBy);
        });

        if ($resetCache) {
            $this->resetCachedAdminData();
        }
    }

    public function applyAdminMatrixUpdateBulk(
        array $companyIds,
        int $verticalId,
        bool $isEnabled,
        bool $makePrimary,
        string $effectiveFrom,
        int $updatedBy
    ): void {
        foreach ($companyIds as $companyId) {
            $this->applyAdminMatrixUpdate(
                (int) $companyId,
                $verticalId,
                $isEnabled,
                $makePrimary,
                $effectiveFrom,
                $updatedBy,
                false
            );
        }

        $this->resetCachedAdminData();
    }

    private function ensurePrimaryForEnabledVerticals(int $companyId, int $updatedBy): void
    {
        if ($this->verticalAdminMatrixRepository->hasEnabledPrimaryVertical($companyId)) {
            return;
        }

        $fallbackId = $this->verticalAdminMatrixRepository->findLatestEnabledVerticalId($companyId);
        if ($fallbackId === null) {
            return;
        }

        $this->verticalAdminMatrixRepository->markVerticalAsPrimary($fallbackId, $updatedBy);
    }

    private function tableExistsCached(string $schema, string $table): bool
    {
        $cacheKey = $schema . '.' . $table;
        if (!array_key_exists($cacheKey, $this->tableExistsCache)) {
            $this->tableExistsCache[$cacheKey] = Cache::remember(
                'vertical_admin_matrix:table_exists:v1:' . $cacheKey,
                self::TABLE_EXISTS_CACHE_TTL_SECONDS,
                function () use ($schema, $table) {
                    return $this->verticalAdminMatrixRepository->tableExists($schema, $table);
                }
            );
        }

        return $this->tableExistsCache[$cacheKey];
    }

    private function resetCachedAdminData(): void
    {
        foreach (array_keys($this->adminMatrixCache) as $systemCompanyId) {
            Cache::forget('vertical_admin_matrix:v1:system_company:' . $systemCompanyId);
        }

        $this->adminMatrixCache = [];
    }
}
