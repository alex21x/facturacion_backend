<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminSettingsMatrixRepository
{
    public function listNonSystemCompanies(int $systemCompanyId): Collection
    {
        return DB::table('core.companies')
            ->where('id', '!=', $systemCompanyId)
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);
    }

    public function companyExists(int $companyId): bool
    {
        return DB::table('core.companies')->where('id', $companyId)->exists();
    }

    public function getFeatureTogglesByCodes(array $featureCodes): Collection
    {
        return DB::table('appcfg.company_feature_toggles')
            ->whereIn('feature_code', $featureCodes)
            ->get(['company_id', 'feature_code', 'is_enabled'])
            ->groupBy('company_id');
    }

    public function getFeatureTogglesByCode(string $featureCode): Collection
    {
        return DB::table('appcfg.company_feature_toggles')
            ->where('feature_code', $featureCode)
            ->get(['company_id', 'is_enabled', 'config'])
            ->keyBy('company_id');
    }

    public function getFeatureToggleRow(int $companyId, string $featureCode): ?CompanyFeatureToggleDTO
    {
        $toggle = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first(['is_enabled', 'config']);

        return $toggle ? CompanyFeatureToggleDTO::fromRow($toggle) : null;
    }

    public function upsertCompanyFeatureTogglesBulk(int $companyId, array $valuesByCode): void
    {
        DB::transaction(function () use ($companyId, $valuesByCode): void {
            foreach ($valuesByCode as $code => $values) {
                DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                    ['company_id' => $companyId, 'feature_code' => (string) $code],
                    $values
                );
            }
        });
    }

    public function upsertCompanyFeatureToggle(int $companyId, string $featureCode, array $values): void
    {
        DB::table('appcfg.company_feature_toggles')->updateOrInsert(
            ['company_id' => $companyId, 'feature_code' => $featureCode],
            $values
        );
    }

    public function getInventorySettingsByCompany(): Collection
    {
        return DB::table('inventory.inventory_settings')
            ->get()
            ->keyBy('company_id');
    }

    public function ensureInventorySettingsSchema(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.inventory_settings (company_id BIGINT PRIMARY KEY, inventory_mode VARCHAR(30) NOT NULL DEFAULT \'KARDEX_SIMPLE\', lot_outflow_strategy VARCHAR(20) NOT NULL DEFAULT \'MANUAL\', allow_negative_stock BOOLEAN NOT NULL DEFAULT FALSE, enforce_lot_for_tracked BOOLEAN NOT NULL DEFAULT FALSE, updated_at TIMESTAMPTZ NULL)');
        DB::statement("ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS complexity_mode VARCHAR(20) NOT NULL DEFAULT 'BASIC'");
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_inventory_pro BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_lot_tracking BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_expiry_tracking BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_advanced_reporting BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_graphical_dashboard BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_location_control BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function upsertInventorySettings(int $companyId, array $updates): void
    {
        DB::table('inventory.inventory_settings')->updateOrInsert(
            ['company_id' => $companyId],
            $updates
        );
    }
}
