<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Facades\DB;

class CompanyProfileRepository
{
    public function companyExistsById(int $companyId): bool
    {
        return DB::table('core.companies')->where('id', $companyId)->exists();
    }

    public function findCompanyProfileRow(int $companyId): ?object
    {
        return DB::table('core.companies')
            ->select('id', 'tax_id', 'legal_name', 'trade_name', 'status')
            ->where('id', $companyId)
            ->first();
    }

    public function findCompanyForBridgePayload(int $companyId): ?object
    {
        return DB::table('core.companies')
            ->where('id', $companyId)
            ->select('tax_id', 'legal_name', 'trade_name')
            ->first();
    }

    public function findLatestCompanySettings(int $companyId, bool $hasLogoPath, bool $hasUpdatedAt, bool $hasCreatedAt): ?object
    {
        $query = DB::table('core.company_settings')->where('company_id', $companyId);

        if ($hasLogoPath) {
            $query->orderByRaw("CASE WHEN COALESCE(logo_path, '') <> '' THEN 0 ELSE 1 END");
        }
        if ($hasUpdatedAt) {
            $query->orderByDesc('updated_at');
        }
        if ($hasCreatedAt) {
            $query->orderByDesc('created_at');
        }

        return $query->first();
    }

    public function findBridgeSettings(int $companyId): ?object
    {
        return DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('address', 'phone', 'email', 'extra_data')
            ->first();
    }

    public function updateCompanyBasic(int $companyId, array $updates): void
    {
        DB::table('core.companies')
            ->where('id', $companyId)
            ->update($updates);
    }

    public function updateCompanySettings(int $companyId, array $updates): int
    {
        return DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->update($updates);
    }

    public function insertCompanySettings(int $companyId, array $values): void
    {
        DB::table('core.company_settings')->insert(array_merge([
            'company_id' => $companyId,
        ], $values));
    }
}
