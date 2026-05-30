<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO;
use App\Application\DTOs\Inventory\InventoryProductReferenceDTO;
use App\Application\DTOs\Inventory\InventoryWarehouseReferenceDTO;
use App\Domain\Inventory\Repositories\InventoryControllerSupportRepositoryInterface;
use Illuminate\Support\Facades\DB;

class InventoryControllerSupportRepository implements InventoryControllerSupportRepositoryInterface
{
    public function listCompanyUnits(int $companyId): array
    {
        return DB::table('core.units as u')
            ->leftJoin('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select([
                'u.id',
                'u.code',
                'u.sunat_uom_code',
                'u.name',
                DB::raw('COALESCE(cu.is_enabled, false) as is_enabled'),
            ])
            ->orderBy('u.name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'code' => $row->code,
                    'sunat_uom_code' => $row->sunat_uom_code,
                    'name' => trim((string) $row->name),
                    'is_enabled' => (bool) $row->is_enabled,
                ];
            })
            ->values()
            ->all();
    }

    public function existingUnitIds(array $unitIds): array
    {
        return DB::table('core.units')
            ->whereIn('id', $unitIds)
            ->pluck('id')
            ->map(function ($value) {
                return (int) $value;
            })
            ->values()
            ->all();
    }

    public function upsertCompanyUnit(int $companyId, int $unitId, bool $isEnabled, int $updatedBy): void
    {
        DB::table('appcfg.company_units')->updateOrInsert(
            [
                'company_id' => $companyId,
                'unit_id' => $unitId,
            ],
            [
                'is_enabled' => $isEnabled,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]
        );
    }

    public function upsertCompanyUnitsBatch(int $companyId, array $items, int $updatedBy): void
    {
        if ($items === []) {
            return;
        }

        $timestamp = now();
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'company_id' => $companyId,
                'unit_id' => (int) ($item['id'] ?? 0),
                'is_enabled' => (bool) ($item['is_enabled'] ?? false),
                'updated_by' => $updatedBy,
                'updated_at' => $timestamp,
            ];
        }

        DB::table('appcfg.company_units')->upsert(
            $rows,
            ['company_id', 'unit_id'],
            ['is_enabled', 'updated_by', 'updated_at']
        );
    }

    public function findCompanyFeatureToggle(int $companyId, string $featureCode): ?CompanyFeatureToggleDTO
    {
        $row = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        return $row ? CompanyFeatureToggleDTO::fromRow($row) : null;
    }

    public function productMasterExists(string $table, int $id, int $companyId): bool
    {
        return DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    public function restaurantRecipesTableExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'restaurant')
            ->where('table_name', 'product_recipes')
            ->exists();
    }

    public function restaurantRecipesDeletedAtColumnExists(): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'restaurant')
            ->where('table_name', 'product_recipes')
            ->where('column_name', 'deleted_at')
            ->exists();
    }

    public function findDefaultUnitId(): ?int
    {
        $row = DB::table('core.units')
            ->where('status', 1)
            ->where(function ($query) {
                $query->whereRaw("UPPER(COALESCE(code, '')) = ?", ['NIU'])
                    ->orWhereRaw("UPPER(COALESCE(sunat_uom_code, '')) = ?", ['NIU'])
                    ->orWhereRaw("UPPER(COALESCE(name, '')) = ?", ['UNIDAD (BIENES)']);
            })
            ->select('id')
            ->orderBy('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    public function listActiveUnits(): array
    {
        return DB::table('core.units')
            ->where('status', 1)
            ->select([
                'id',
                DB::raw("UPPER(TRIM(COALESCE(code, ''))) as normalized_code"),
                DB::raw("UPPER(TRIM(COALESCE(sunat_uom_code, ''))) as normalized_sunat_uom_code"),
                DB::raw("UPPER(TRIM(COALESCE(name, ''))) as normalized_name"),
            ])
            ->get()
            ->all();
    }

    public function findWarehouseIdByCodeOrName(int $companyId, string $normalizedCode): ?int
    {
        $row = DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where(function ($query) use ($normalizedCode) {
                $query->whereRaw("UPPER(COALESCE(code, '')) = ?", [$normalizedCode])
                    ->orWhereRaw("UPPER(COALESCE(name, '')) = ?", [$normalizedCode]);
            })
            ->where('status', 1)
            ->select('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    public function findDefaultWarehouseForImport(int $companyId): ?InventoryWarehouseReferenceDTO
    {
        $row = DB::table('inventory.warehouses as w')
            ->leftJoin('core.branches as b', function ($join) {
                $join->on('b.id', '=', 'w.branch_id')
                    ->on('b.company_id', '=', 'w.company_id');
            })
            ->where('w.company_id', $companyId)
            ->where('w.status', 1)
            ->orderByRaw("CASE
                WHEN UPPER(COALESCE(w.code, '')) IN ('WH01', 'PRINCIPAL', 'MAIN') THEN 0
                WHEN UPPER(COALESCE(w.name, '')) LIKE '%PRINCIPAL%' THEN 1
                WHEN COALESCE(b.is_main, false) = true THEN 2
                ELSE 3
            END")
            ->orderBy('w.id')
            ->select(['w.id', 'w.code'])
            ->first();

        return $row ? InventoryWarehouseReferenceDTO::fromRow($row) : null;
    }

    public function findCurrentStock(int $companyId, int $warehouseId, int $productId): float
    {
        $row = DB::table('inventory.current_stock')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        return $row ? (float) ($row->stock ?? 0) : 0.0;
    }

    public function findActiveProductBySku(int $companyId, string $sku, ?int $excludeProductId = null): ?InventoryProductReferenceDTO
    {
        $query = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereRaw("UPPER(COALESCE(sku, '')) = ?", [strtoupper(trim($sku))]);

        if ($excludeProductId !== null) {
            $query->where('id', '<>', $excludeProductId);
        }

        $row = $query->select('id')->first();

        return $row ? InventoryProductReferenceDTO::fromRow($row) : null;
    }

    public function findActiveProductByBarcode(int $companyId, string $barcode, ?int $excludeProductId = null): ?InventoryProductReferenceDTO
    {
        $query = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('barcode', trim($barcode));

        if ($excludeProductId !== null) {
            $query->where('id', '<>', $excludeProductId);
        }

        $row = $query->select('id')->first();

        return $row ? InventoryProductReferenceDTO::fromRow($row) : null;
    }

    public function findActiveProductByNameNatureUnit(
        int $companyId,
        string $name,
        string $nature,
        ?int $unitId,
        ?int $excludeProductId = null
    ): ?InventoryProductReferenceDTO {
        $query = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereRaw("UPPER(TRIM(COALESCE(name, ''))) = ?", [strtoupper(trim($name))])
            ->where('product_nature', $nature);

        if ($unitId === null) {
            $query->whereNull('unit_id');
        } else {
            $query->where('unit_id', $unitId);
        }

        if ($excludeProductId !== null) {
            $query->where('id', '<>', $excludeProductId);
        }

        $row = $query->select('id')->first();

        return $row ? InventoryProductReferenceDTO::fromRow($row) : null;
    }
}
