<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\CompanyBridgePayloadDTO;
use App\Application\DTOs\AppConfig\CompanyProfileDTO;
use App\Application\DTOs\AppConfig\CompanySettingsDTO;
use Illuminate\Support\Facades\DB;

class CompanyProfileRepository
{
    public function companyExistsById(int $companyId): bool
    {
        return DB::table('core.companies')->where('id', $companyId)->exists();
    }

    public function findCompanyProfileRow(int $companyId): ?CompanyProfileDTO
    {
        $company = DB::table('core.companies')
            ->select('id', 'tax_id', 'legal_name', 'trade_name', 'status')
            ->where('id', $companyId)
            ->first();

        return $company ? CompanyProfileDTO::fromRow($company) : null;
    }

    public function findCompanyForBridgePayload(int $companyId): ?CompanyBridgePayloadDTO
    {
        $company = DB::table('core.companies')
            ->where('id', $companyId)
            ->select('tax_id', 'legal_name', 'trade_name')
            ->first();

        return $company ? CompanyBridgePayloadDTO::fromRow($company) : null;
    }

    public function findLatestCompanySettings(int $companyId, bool $hasLogoPath, bool $hasUpdatedAt, bool $hasCreatedAt): ?CompanySettingsDTO
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

        $settings = $query->first();

        return $settings ? CompanySettingsDTO::fromRow($settings) : null;
    }

    public function findBridgeSettings(int $companyId): ?CompanySettingsDTO
    {
        $settings = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('address', 'phone', 'email', 'extra_data')
            ->first();

        return $settings ? CompanySettingsDTO::fromRow($settings) : null;
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
