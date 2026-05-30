<?php

namespace App\Infrastructure\Repositories\Purchases;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchasesPersistenceRepository
{
    public function findPurchaseOrderSource(int $id, int $companyId): ?\App\Application\DTOs\Purchases\PurchaseStockEntryDTO
    {
        $entry = DB::table('inventory.stock_entries')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->where('entry_type', 'PURCHASE_ORDER')
            ->first();

        return $entry ? \App\Application\DTOs\Purchases\PurchaseStockEntryDTO::fromRow($entry) : null;
    }

    public function listStockEntryItems(int $entryId): Collection
    {
        return DB::table('inventory.stock_entry_items')
            ->where('entry_id', $entryId)
            ->orderBy('id')
            ->get();
    }

    public function receivedByProductForPurchaseOrder(int $companyId, int $sourceId): array
    {
        return DB::table('inventory.stock_entries as se')
            ->join('inventory.stock_entry_items as sei', 'sei.entry_id', '=', 'se.id')
            ->where('se.company_id', $companyId)
            ->where('se.entry_type', 'PURCHASE')
            ->where('se.status', 'APPLIED')
            ->whereRaw("COALESCE((se.metadata->>'source_purchase_order_id')::BIGINT, 0) = ?", [$sourceId])
            ->groupBy('sei.product_id')
            ->selectRaw('sei.product_id, COALESCE(SUM(sei.qty), 0) as received_qty')
            ->get()
            ->reduce(function ($carry, $row) {
                $carry[(int) $row->product_id] = (float) $row->received_qty;
                return $carry;
            }, []);
    }

    public function updateStockEntryStatusAndMetadata(int $id, int $companyId, array $updates): int
    {
        return DB::table('inventory.stock_entries')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($updates);
    }

    public function findStockEntry(int $id, int $companyId): ?\App\Application\DTOs\Purchases\PurchaseStockEntryDTO
    {
        $entry = DB::table('inventory.stock_entries')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return $entry ? \App\Application\DTOs\Purchases\PurchaseStockEntryDTO::fromRow($entry) : null;
    }

    public function findProductsByCompanyAndIds(int $companyId, array $productIds): Collection
    {
        return DB::table('inventory.products')
            ->select('id', 'status', 'is_stockable', 'lot_tracking')
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');
    }

    public function runInTransaction(callable $callback)
    {
        return DB::transaction($callback);
    }

    public function deleteStockEntryItems(int $entryId): int
    {
        return DB::table('inventory.stock_entry_items')
            ->where('entry_id', $entryId)
            ->delete();
    }

    public function createProductLot(array $payload): int
    {
        return (int) DB::table('inventory.product_lots')->insertGetId($payload);
    }

    public function productLotExists(int $lotId, int $companyId, int $warehouseId, int $productId): bool
    {
        return DB::table('inventory.product_lots')
            ->where('id', $lotId)
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('status', 1)
            ->exists();
    }

    public function insertStockEntryItem(array $payload): bool
    {
        return DB::table('inventory.stock_entry_items')->insert($payload);
    }

    public function updateProductCostPrice(int $companyId, int $productId, float $commercialUnitCost): int
    {
        return DB::table('inventory.products')
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->update([
                'cost_price' => $commercialUnitCost,
            ]);
    }

    public function listEntryItemsForAttach(int $companyId, array $entryIds, bool $hasTaxCategory, bool $hasTaxRate, bool $hasItemMetadata): Collection
    {
        return DB::table('inventory.stock_entry_items as sei')
            ->leftJoin('inventory.products as p', 'sei.product_id', '=', 'p.id')
            ->leftJoin('inventory.product_lots as pl', 'sei.lot_id', '=', 'pl.id')
            ->whereIn('sei.entry_id', $entryIds)
            ->select([
                'sei.entry_id',
                'sei.product_id',
                DB::raw('COALESCE(p.name, CONCAT(\'Producto #\', sei.product_id)) as product_name'),
                'sei.qty',
                'sei.unit_cost',
                $hasTaxCategory ? 'sei.tax_category_id' : DB::raw('NULL as tax_category_id'),
                $hasTaxRate ? 'sei.tax_rate' : DB::raw('0 as tax_rate'),
                $hasItemMetadata ? 'sei.metadata' : DB::raw('NULL as metadata'),
                'sei.notes',
                'pl.lot_code',
            ])
            ->orderBy('sei.entry_id')
            ->orderBy('p.name')
            ->orderBy('sei.id')
            ->get();
    }

    public function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        return isset($row->present) && (bool) $row->present;
    }

    public function tableColumns(string $qualifiedTable): array
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $rows = DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [$schema, $table]
        );

        return collect($rows)->map(function ($row) {
            return (string) $row->column_name;
        })->values()->all();
    }

    public function getInventorySettingsRow(int $companyId): ?\App\Application\DTOs\Inventory\InventorySettingsDTO
    {
        $settings = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        return $settings ? \App\Application\DTOs\Inventory\InventorySettingsDTO::fromRow($settings) : null;
    }

    public function hasDuplicatePurchaseByReference(
        int $companyId,
        string $normalizedReferenceNo,
        string $normalizedSupplierReference,
        ?int $excludeEntryId = null
    ): bool {
        $query = DB::table('inventory.stock_entries')
            ->where('company_id', $companyId)
            ->where('entry_type', 'PURCHASE')
            ->whereRaw("UPPER(COALESCE(status, '')) NOT IN ('VOID', 'CANCELED')")
            ->whereRaw("UPPER(REGEXP_REPLACE(TRIM(COALESCE(reference_no, '')), '\\s+', ' ', 'g')) = ?", [$normalizedReferenceNo])
            ->whereRaw("UPPER(REGEXP_REPLACE(TRIM(COALESCE(supplier_reference, '')), '\\s+', ' ', 'g')) = ?", [$normalizedSupplierReference]);

        if ($excludeEntryId !== null && $excludeEntryId > 0) {
            $query->where('id', '<>', $excludeEntryId);
        }

        return $query->exists();
    }

    public function listLedgerByStockEntry(int $companyId, int $entryId): Collection
    {
        return DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'STOCK_ENTRY')
            ->where('ref_id', $entryId)
            ->orderBy('id')
            ->get();
    }

    public function insertLedgerRow(array $payload): bool
    {
        return DB::table('inventory.inventory_ledger')->insert($payload);
    }

    public function clearEditLedgerForEntry(int $companyId, int $entryId): int
    {
        return DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'STOCK_ENTRY_EDIT')
            ->where('ref_id', $entryId)
            ->delete();
    }

    public function findCurrentStockRow(int $companyId, int $warehouseId, int $productId): ?\App\Application\DTOs\Inventory\InventoryStockLevelDTO
    {
        $stock = DB::table('inventory.current_stock')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        return $stock ? \App\Application\DTOs\Inventory\InventoryStockLevelDTO::fromRow($stock) : null;
    }

    public function findCurrentStockByLotRow(int $companyId, int $warehouseId, int $productId, int $lotId): ?\App\Application\DTOs\Inventory\InventoryStockLevelDTO
    {
        $stock = DB::table('inventory.current_stock_by_lot')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('lot_id', $lotId)
            ->first();

        return $stock ? \App\Application\DTOs\Inventory\InventoryStockLevelDTO::fromRow($stock) : null;
    }

    public function findCompanySettingsBankAccounts(int $companyId): ?\App\Application\DTOs\AppConfig\CompanySettingsDTO
    {
        $settings = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('bank_accounts')
            ->first();

        return $settings ? \App\Application\DTOs\AppConfig\CompanySettingsDTO::fromRow($settings) : null;
    }

    public function findCompanyFeatureToggle(int $companyId, string $featureCode): ?\App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO
    {
        $toggle = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        return $toggle ? \App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO::fromRow($toggle) : null;
    }

    public function findBranchFeatureToggle(int $companyId, $branchId, string $featureCode): ?\App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO
    {
        $toggle = DB::table('appcfg.branch_feature_toggles')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('feature_code', $featureCode)
            ->first();

        return $toggle ? \App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO::fromRow($toggle) : null;
    }

    public function listDetractionServiceCodes(): Collection
    {
        return DB::table('master.detraccion_service_codes')
            ->select('id', 'code', 'name', 'rate_percent')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
    }

    public function executeStatement(string $sql, array $bindings = []): bool
    {
        return DB::statement($sql, $bindings);
    }

    public function findPurchaseSupplierByDocument(int $companyId, string $document): ?\App\Application\DTOs\Purchases\PurchaseSupplierDTO
    {
        $supplier = DB::table('inventory.purchase_suppliers')
            ->select(['id', 'doc_type', 'doc_number', 'legal_name', 'address', 'phone', 'source'])
            ->where('company_id', $companyId)
            ->where('doc_number', $document)
            ->first();

        return $supplier ? \App\Application\DTOs\Purchases\PurchaseSupplierDTO::fromRow($supplier) : null;
    }

    public function upsertPurchaseSupplier(int $companyId, array $data): bool
    {
        return DB::statement(
            'INSERT INTO inventory.purchase_suppliers (company_id, doc_type, doc_number, legal_name, address, phone, source, created_at, updated_at, last_used_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW()) '
            . 'ON CONFLICT (company_id, doc_number) DO UPDATE SET '
            . 'doc_type = EXCLUDED.doc_type, legal_name = EXCLUDED.legal_name, address = EXCLUDED.address, phone = EXCLUDED.phone, source = EXCLUDED.source, updated_at = NOW(), last_used_at = NOW()',
            [
                $companyId,
                (string) ($data['doc_type'] ?? ''),
                (string) ($data['doc_number'] ?? ''),
                (string) ($data['legal_name'] ?? ''),
                $data['address'] ?? null,
                $data['phone'] ?? null,
                $data['source'] ?? null,
            ]
        );
    }

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') !== false) {
            return explode('.', $qualifiedTable, 2);
        }

        return ['public', $qualifiedTable];
    }
}
