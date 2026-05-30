<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\AdminSettingsMatrixRepository;

class AdminSettingsMatrixCommandService
{
    private static bool $inventorySettingsSchemaEnsured = false;

    public function __construct(private AdminSettingsMatrixRepository $repository)
    {
    }

    public function upsertCompanyFeatureTogglesBulk(int $companyId, array $valuesByCode): void
    {
        $this->repository->upsertCompanyFeatureTogglesBulk($companyId, $valuesByCode);
    }

    public function upsertCompanyFeatureToggle(int $companyId, string $featureCode, array $values): void
    {
        $this->repository->upsertCompanyFeatureToggle($companyId, $featureCode, $values);
    }

    public function ensureInventorySettingsSchema(): void
    {
        if (self::$inventorySettingsSchemaEnsured) {
            return;
        }

        $this->repository->ensureInventorySettingsSchema();
        self::$inventorySettingsSchemaEnsured = true;
    }

    public function upsertInventorySettings(int $companyId, array $updates): void
    {
        $this->repository->upsertInventorySettings($companyId, $updates);
    }
}
