<?php

namespace App\Services\Inventory;

use App\Application\DTOs\Inventory\InventoryProductReferenceDTO;
use App\Domain\Inventory\Repositories\InventoryControllerSupportRepositoryInterface;

class InventoryControllerSupportService
{
    private ?array $unitLookupMapCache = null;

    public function __construct(private InventoryControllerSupportRepositoryInterface $repository)
    {
    }

    public function listCompanyUnits(int $companyId): array
    {
        return $this->repository->listCompanyUnits($companyId);
    }

    public function updateCompanyUnits(int $companyId, array $items, int $updatedBy): void
    {
        $this->repository->upsertCompanyUnitsBatch($companyId, $items, $updatedBy);
    }

    public function existingUnitIds(array $unitIds): array
    {
        return $this->repository->existingUnitIds($unitIds);
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
        if ($this->unitLookupMapCache !== null) {
            return $this->unitLookupMapCache;
        }

        $rows = $this->repository->listActiveUnits();
        $map = [];

        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            foreach ([
                (string) ($row->normalized_code ?? ''),
                (string) ($row->normalized_sunat_uom_code ?? ''),
                (string) ($row->normalized_name ?? ''),
            ] as $normalized) {
                if ($normalized !== '') {
                    $map[$normalized] = $id;
                }
            }
        }

        $this->unitLookupMapCache = $map;

        return $this->unitLookupMapCache;
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
    ): ?InventoryProductReferenceDTO {
        if ($sku !== null) {
            $product = $this->repository->findActiveProductBySku($companyId, $sku, $excludeProductId);
            if ($product) {
                return $product;
            }
        }

        if ($barcode !== null) {
            $product = $this->repository->findActiveProductByBarcode($companyId, $barcode, $excludeProductId);
            if ($product) {
                return $product;
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
