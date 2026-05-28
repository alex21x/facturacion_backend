<?php

namespace App\Domain\Inventory\Repositories;

interface InventoryControllerSupportRepositoryInterface
{
    public function ensureCompanyUnitsTable(): void;

    public function listCompanyUnits(int $companyId): array;

    public function existingUnitIds(array $unitIds): array;

    public function upsertCompanyUnit(int $companyId, int $unitId, bool $isEnabled, int $updatedBy): void;

    public function ensureProductCatalogSchema(): void;

    public function findCompanyFeatureToggle(int $companyId, string $featureCode): ?object;

    public function productMasterExists(string $table, int $id, int $companyId): bool;

    public function restaurantRecipesTableExists(): bool;

    public function restaurantRecipesDeletedAtColumnExists(): bool;

    public function findDefaultUnitId(): ?int;

    public function listActiveUnits(): array;

    public function findWarehouseIdByCodeOrName(int $companyId, string $normalizedCode): ?int;

    public function findDefaultWarehouseForImport(int $companyId): ?object;

    public function findCurrentStock(int $companyId, int $warehouseId, int $productId): float;

    public function findActiveProductBySku(int $companyId, string $sku, ?int $excludeProductId = null): ?object;

    public function findActiveProductByBarcode(int $companyId, string $barcode, ?int $excludeProductId = null): ?object;

    public function findActiveProductByNameNatureUnit(
        int $companyId,
        string $name,
        string $nature,
        ?int $unitId,
        ?int $excludeProductId = null
    ): ?object;
}
