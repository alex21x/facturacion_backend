<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\CompanyAccessLinkDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompanyAccessLinkRepository
{
    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function getActiveByCompanyIds(array $companyIds): Collection
    {
        return DB::table('appcfg.company_access_links')
            ->whereIn('company_id', $companyIds)
            ->where('is_active', true)
            ->get(['company_id', 'access_slug'])
            ->keyBy('company_id');
    }

    public function getByCompanyIds(array $companyIds): Collection
    {
        return DB::table('appcfg.company_access_links')
            ->whereIn('company_id', $companyIds)
            ->get(['company_id', 'access_slug'])
            ->keyBy('company_id');
    }

    public function existsByCompanyId(int $companyId): bool
    {
        return DB::table('appcfg.company_access_links')
            ->where('company_id', $companyId)
            ->exists();
    }

    public function findByCompanyId(int $companyId): ?CompanyAccessLinkDTO
    {
        $row = DB::table('appcfg.company_access_links')
            ->where('company_id', $companyId)
            ->first(['access_slug']);

        return $row ? CompanyAccessLinkDTO::fromRow($row) : null;
    }

    public function slugExistsForOtherCompany(string $slug, int $companyId): bool
    {
        return DB::table('appcfg.company_access_links')
            ->where('access_slug', $slug)
            ->where('company_id', '!=', $companyId)
            ->exists();
    }

    public function updateOrInsertByCompanyId(int $companyId, array $values): void
    {
        DB::table('appcfg.company_access_links')->updateOrInsert(
            ['company_id' => $companyId],
            $values
        );
    }
}
