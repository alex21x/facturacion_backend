<?php

namespace App\Services\Inventory;

use App\Domain\Inventory\Repositories\InventoryControllerSupportRepositoryInterface;

class InventoryControllerSupportService
{
    public function __construct(private InventoryControllerSupportRepositoryInterface $repository)
    {
    }

    public function ensureCompanyUnitsTable(): void
    {
        $this->repository->ensureCompanyUnitsTable();
    }

    public function listCompanyUnits(int $companyId): array
    {
        $this->repository->ensureCompanyUnitsTable();

        return $this->repository->listCompanyUnits($companyId);
    }

    public function updateCompanyUnits(int $companyId, array $items, int $updatedBy): void
    {
        $this->repository->ensureCompanyUnitsTable();

        foreach ($items as $item) {
            $this->repository->upsertCompanyUnit(
                $companyId,
                (int) $item['id'],
                (bool) $item['is_enabled'],
                $updatedBy
            );
        }
    }

    public function existingUnitIds(array $unitIds): array
    {
        return $this->repository->existingUnitIds($unitIds);
    }

    public function ensureProductCatalogSchema(): void
    {
        $this->repository->ensureProductCatalogSchema();
    }

    public function isAllowedByProfileFeature($authUser, int $companyId, string $featureCode): bool
    {
        $row = $this->repository->findCompanyFeatureToggle($companyId, $featureCode);

        if (!$row || !(bool) $row->is_enabled) {
            return true;
        }

        $config = [];
        if ($row->config !== null) {
            $decoded = json_decode((string) $row->config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        $allowSeller = (bool) ($config['allow_seller'] ?? true);
        $allowCashier = (bool) ($config['allow_cashier'] ?? true);
        $allowAdmin = (bool) ($config['allow_admin'] ?? true);

        $roleProfile = strtoupper((string) ($authUser->role_profile ?? ''));
        $roleCode = strtoupper((string) ($authUser->role_code ?? ''));

        if ($roleProfile === 'SELLER') {
            return $allowSeller;
        }
        if ($roleProfile === 'CASHIER') {
            return $allowCashier;
        }
        if ($roleCode === 'ADMIN' || $roleProfile === 'GENERAL') {
            return $allowAdmin;
        }

        return $allowAdmin;
    }

    public function productMasterExists(string $table, int $id, int $companyId): bool
    {
        return $this->repository->productMasterExists($table, $id, $companyId);
    }

    public function restaurantRecipesTableExists(): bool
    {
        return $this->repository->restaurantRecipesTableExists();
    }

    public function restaurantRecipesDeletedAtColumnExists(): bool
    {
        return $this->repository->restaurantRecipesDeletedAtColumnExists();
    }

    public function resolveDefaultUnitId(): ?int
    {
        return $this->repository->findDefaultUnitId();
    }

    public function buildUnitLookupMap(): array
    {
        $rows = $this->repository->listActiveUnits();
        $map = [];

        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            foreach ([(string) ($row->code ?? ''), (string) ($row->sunat_uom_code ?? ''), (string) ($row->name ?? '')] as $key) {
                $normalized = strtoupper(trim($key));
                if ($normalized !== '') {
                    $map[$normalized] = $id;
                }
            }
        }

        return $map;
    }

    public function resolveWarehouseIdFromCode(int $companyId, string $warehouseCode, array &$cache): ?int
    {
        $normalized = strtoupper(trim($warehouseCode));
        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, $cache)) {
            return $cache[$normalized];
        }

        $cache[$normalized] = $this->repository->findWarehouseIdByCodeOrName($companyId, $normalized);

        return $cache[$normalized];
    }

    public function resolveDefaultWarehouseForImport(int $companyId): ?array
    {
        $row = $this->repository->findDefaultWarehouseForImport($companyId);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'code' => strtoupper(trim((string) ($row->code ?? ''))),
        ];
    }

    public function getCurrentStockForProjection(int $companyId, int $warehouseId, int $productId): float
    {
        return $this->repository->findCurrentStock($companyId, $warehouseId, $productId);
    }

    public function findExistingActiveProduct(
        int $companyId,
        ?string $sku,
        ?string $barcode,
        string $name,
        ?int $unitId,
        string $nature,
        ?int $excludeProductId = null
    ): ?object {
        if ($sku !== null) {
            $row = $this->repository->findActiveProductBySku($companyId, $sku, $excludeProductId);
            if ($row) {
                return $row;
            }
        }

        if ($barcode !== null) {
            $row = $this->repository->findActiveProductByBarcode($companyId, $barcode, $excludeProductId);
            if ($row) {
                return $row;
            }
        }

        return $this->repository->findActiveProductByNameNatureUnit(
            $companyId,
            $name,
            $nature,
            $unitId,
            $excludeProductId
        );
    }
}
