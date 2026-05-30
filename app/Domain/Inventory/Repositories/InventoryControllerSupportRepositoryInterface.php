<?php

namespace App\Domain\Inventory\Repositories;

use App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO;
use App\Application\DTOs\Inventory\InventoryProductReferenceDTO;
use App\Application\DTOs\Inventory\InventoryWarehouseReferenceDTO;

interface InventoryControllerSupportRepositoryInterface
{
    public function listCompanyUnits(int $companyId): array;

    public function existingUnitIds(array $unitIds): array;

    public function upsertCompanyUnit(int $companyId, int $unitId, bool $isEnabled, int $updatedBy): void;

    public function upsertCompanyUnitsBatch(int $companyId, array $items, int $updatedBy): void;

    public function findCompanyFeatureToggle(int $companyId, string $featureCode): ?CompanyFeatureToggleDTO;

    public function productMasterExists(string $table, int $id, int $companyId): bool;

    public function restaurantRecipesTableExists(): bool;

    public function restaurantRecipesDeletedAtColumnExists(): bool;

    public function findDefaultUnitId(): ?int;

    public function listActiveUnits(): array;

    public function findWarehouseIdByCodeOrName(int $companyId, string $normalizedCode): ?int;

    public function findDefaultWarehouseForImport(int $companyId): ?InventoryWarehouseReferenceDTO;

    public function findCurrentStock(int $companyId, int $warehouseId, int $productId): float;

    public function findActiveProductBySku(int $companyId, string $sku, ?int $excludeProductId = null): ?InventoryProductReferenceDTO;

    public function findActiveProductByBarcode(int $companyId, string $barcode, ?int $excludeProductId = null): ?InventoryProductReferenceDTO;

    public function findActiveProductByNameNatureUnit(
        int $companyId,
        string $name,
        string $nature,
        ?int $unitId,
        ?int $excludeProductId = null
    ): ?InventoryProductReferenceDTO;
}
