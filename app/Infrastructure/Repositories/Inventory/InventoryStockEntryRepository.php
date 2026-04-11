<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Domain\Inventory\Repositories\InventoryStockEntryRepositoryInterface;
use App\Support\Inventory\OutboxEngine;
use Illuminate\Support\Facades\DB;

class InventoryStockEntryRepository implements InventoryStockEntryRepositoryInterface
{
    private array $stockProjection = [];
    private array $lotStockProjection = [];

    public function createAppliedStockEntry(object $authUser, array $payload, int $companyId, $branchId, int $warehouseId): array
    {
        $warehouseExists = DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', (int) $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->exists();

        if (!$warehouseExists) {
            throw new \RuntimeException('Invalid warehouse scope');
        }

        $this->ensureStockEntriesTables();
        OutboxEngine::ensureSchema();

        $stockEntryColumns = $this->tableColumns('inventory.stock_entries');
        $stockEntryItemColumns = $this->tableColumns('inventory.stock_entry_items');
        $inventoryLedgerColumns = $this->tableColumns('inventory.inventory_ledger');

        $hasPaymentMethodColumn = in_array('payment_method_id', $stockEntryColumns, true);
        $hasMetadataColumn = in_array('metadata', $stockEntryColumns, true);
        $hasItemTaxCategoryColumn = in_array('tax_category_id', $stockEntryItemColumns, true);
        $hasItemTaxRateColumn = in_array('tax_rate', $stockEntryItemColumns, true);
        $hasLedgerTaxRateColumn = in_array('tax_rate', $inventoryLedgerColumns, true);
        $taxCategoriesTable = $hasItemTaxCategoryColumn ? $this->resolveTaxCategoriesTable() : null;
        $taxCategoryColumns = $taxCategoriesTable ? $this->tableColumns($taxCategoriesTable) : [];
        $taxCategoryStatusColumn = $this->firstExistingColumn($taxCategoryColumns, ['status', 'is_enabled', 'enabled', 'active']);
        $taxCategoryCompanyColumn = $this->firstExistingColumn($taxCategoryColumns, ['company_id']);

        $productIds = collect($payload['items'])
            ->pluck('product_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->unique()
            ->values();

        $products = DB::table('inventory.products')
            ->select('id', 'name', 'is_stockable', 'lot_tracking', 'has_expiration', 'status')
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds->all())
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $settings = $this->inventorySettingsForCompany($companyId);

        return DB::transaction(function () use (
            $payload,
            $authUser,
            $companyId,
            $branchId,
            $warehouseId,
            $products,
            $settings,
            $hasPaymentMethodColumn,
            $hasMetadataColumn,
            $hasItemTaxCategoryColumn,
            $hasItemTaxRateColumn,
            $hasLedgerTaxRateColumn,
            $taxCategoriesTable,
            $taxCategoryStatusColumn,
            $taxCategoryCompanyColumn
        ) {
            $entryType = strtoupper((string) $payload['entry_type']);
            $appliesStock = in_array($entryType, ['PURCHASE', 'ADJUSTMENT'], true);
            $isPurchaseOrder = $entryType === 'PURCHASE_ORDER';
            $entryStatus = $isPurchaseOrder ? 'OPEN' : 'APPLIED';
            $inventoryProEnabled = (bool) ($settings['enable_inventory_pro'] ?? false);
            $lotTrackingEnabled = $inventoryProEnabled && (bool) ($settings['enable_lot_tracking'] ?? false);
            $expiryTrackingEnabled = $lotTrackingEnabled && (bool) ($settings['enable_expiry_tracking'] ?? false);

            $purchaseTaxMetadata = null;
            if ($entryType === 'PURCHASE') {
                $purchaseTaxMetadata = $this->normalizePurchaseTributaryMetadata(
                    is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                    $companyId,
                    $branchId,
                    $payload['items']
                );
            }

            $entryInsert = [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'entry_type' => $entryType,
                'reference_no' => $payload['reference_no'] ?? null,
                'supplier_reference' => $payload['supplier_reference'] ?? null,
                'issue_at' => $payload['issue_at'] ?? now(),
                'status' => $entryStatus,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $authUser->id,
                'updated_by' => $authUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasPaymentMethodColumn) {
                $entryInsert['payment_method_id'] = isset($payload['payment_method_id']) ? (int) $payload['payment_method_id'] : null;
            }
            if ($hasMetadataColumn) {
                $entryInsert['metadata'] = $purchaseTaxMetadata ? json_encode($purchaseTaxMetadata) : null;
            }

            $entryId = DB::table('inventory.stock_entries')->insertGetId($entryInsert);

            foreach ($payload['items'] as $index => $item) {
                $productId = (int) $item['product_id'];
                $product = $products->get($productId);

                if (!$product) {
                    throw new \RuntimeException('Product not found for line ' . ($index + 1));
                }

                if ((int) $product->status !== 1) {
                    throw new \RuntimeException('Product inactive for line ' . ($index + 1));
                }

                if (!(bool) $product->is_stockable) {
                    throw new \RuntimeException('Product is not stockable for line ' . ($index + 1));
                }

                $qty = round((float) $item['qty'], 8);

                if ($entryType === 'PURCHASE' && $qty <= 0) {
                    throw new \RuntimeException('Purchase line quantity must be positive for line ' . ($index + 1));
                }

                if ($entryType === 'ADJUSTMENT' && abs($qty) < 0.00000001) {
                    throw new \RuntimeException('Adjustment line quantity cannot be zero for line ' . ($index + 1));
                }

                if ($entryType === 'PURCHASE_ORDER' && $qty <= 0) {
                    throw new \RuntimeException('Purchase order line quantity must be positive for line ' . ($index + 1));
                }

                $lotId = $lotTrackingEnabled && isset($item['lot_id']) ? (int) $item['lot_id'] : null;
                $lotCode = $lotTrackingEnabled && isset($item['lot_code']) ? trim((string) $item['lot_code']) : null;
                $manufactureAt = $expiryTrackingEnabled ? ($item['manufacture_at'] ?? null) : null;
                $expiresAt = $expiryTrackingEnabled ? ($item['expires_at'] ?? null) : null;
                $taxCategoryId = isset($item['tax_category_id']) ? (int) $item['tax_category_id'] : null;
                $taxRate = isset($item['tax_rate']) ? round((float) $item['tax_rate'], 2) : 0.0;

                if ($appliesStock && $lotTrackingEnabled && (bool) $product->lot_tracking && (bool) $settings['enforce_lot_for_tracked'] && !$lotId && !$lotCode) {
                    throw new \RuntimeException('Lot is required for tracked product line ' . ($index + 1));
                }

                if ($taxCategoryId && $hasItemTaxCategoryColumn) {
                    if (!$taxCategoriesTable) {
                        throw new \RuntimeException('Tax categories table not found for line ' . ($index + 1));
                    }

                    $taxCategoryQuery = DB::table($taxCategoriesTable)
                        ->where('id', $taxCategoryId);

                    if ($taxCategoryStatusColumn) {
                        if ($taxCategoryStatusColumn === 'status') {
                            $taxCategoryQuery->where($taxCategoryStatusColumn, 1);
                        } else {
                            $taxCategoryQuery->where($taxCategoryStatusColumn, true);
                        }
                    }

                    if ($taxCategoryCompanyColumn) {
                        $taxCategoryQuery->where(function ($nested) use ($taxCategoryCompanyColumn, $companyId) {
                            $nested->where($taxCategoryCompanyColumn, $companyId)
                                ->orWhereNull($taxCategoryCompanyColumn);
                        });
                    }

                    $taxCategoryExists = $taxCategoryQuery->exists();

                    if (!$taxCategoryExists) {
                        throw new \RuntimeException('Tax category not found for line ' . ($index + 1));
                    }
                }

                if ($appliesStock && $lotId) {
                    $lotExists = DB::table('inventory.product_lots')
                        ->where('id', $lotId)
                        ->where('company_id', $companyId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->where('status', 1)
                        ->exists();

                    if (!$lotExists) {
                        throw new \RuntimeException('Lot not found for line ' . ($index + 1));
                    }
                }

                if ($appliesStock && !$lotId && $lotCode !== null && $lotCode !== '') {
                    $lotId = DB::table('inventory.product_lots')->insertGetId([
                        'company_id' => $companyId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'lot_code' => $lotCode,
                        'manufacture_at' => $manufactureAt,
                        'expires_at' => $expiresAt,
                        'received_at' => $payload['issue_at'] ?? now(),
                        'status' => 1,
                        'created_by' => $authUser->id,
                        'created_at' => now(),
                    ]);
                }

                $movementType = $entryType === 'PURCHASE' ? 'IN' : ($qty >= 0 ? 'IN' : 'OUT');
                $unitCost = isset($item['unit_cost']) ? (float) $item['unit_cost'] : 0.0;

                if ($appliesStock) {
                    $this->applyCurrentStockDelta(
                        $companyId,
                        $warehouseId,
                        $productId,
                        $qty,
                        (bool) $settings['allow_negative_stock']
                    );

                    if ($lotId) {
                        $this->applyLotStockDelta(
                            $companyId,
                            $warehouseId,
                            $productId,
                            (int) $lotId,
                            $qty,
                            (bool) $settings['allow_negative_stock']
                        );
                    }
                }

                $entryItemInsert = [
                    'entry_id' => $entryId,
                    'product_id' => $productId,
                    'lot_id' => $lotId,
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                    'notes' => $item['notes'] ?? null,
                    'created_at' => now(),
                ];

                if ($hasItemTaxCategoryColumn) {
                    $entryItemInsert['tax_category_id'] = $taxCategoryId;
                }

                if ($hasItemTaxRateColumn) {
                    $entryItemInsert['tax_rate'] = $taxRate;
                }

                DB::table('inventory.stock_entry_items')->insert($entryItemInsert);

                if ($appliesStock) {
                    $ledgerInsert = [
                        'company_id' => $companyId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'lot_id' => $lotId,
                        'movement_type' => $movementType,
                        'quantity' => round(abs($qty), 8),
                        'unit_cost' => $unitCost,
                        'ref_type' => 'STOCK_ENTRY',
                        'ref_id' => $entryId,
                        'notes' => $payload['notes'] ?? null,
                        'moved_at' => $payload['issue_at'] ?? now(),
                        'created_by' => $authUser->id,
                    ];

                    if ($hasLedgerTaxRateColumn) {
                        $ledgerInsert['tax_rate'] = $taxRate;
                    }

                    DB::table('inventory.inventory_ledger')->insert($ledgerInsert);

                    OutboxEngine::enqueue(
                        $companyId,
                        'STOCK_ENTRY',
                        (string) $entryId,
                        'INVENTORY_MOVEMENT_APPLIED',
                        [
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'warehouse_id' => $warehouseId,
                            'entry_id' => (int) $entryId,
                            'entry_type' => $entryType,
                            'line_no' => $index + 1,
                            'product_id' => $productId,
                            'lot_id' => $lotId,
                            'movement_type' => $movementType,
                            'quantity' => round(abs($qty), 8),
                            'unit_cost' => $unitCost,
                            'tax_rate' => $taxRate,
                            'ref_type' => 'STOCK_ENTRY',
                            'ref_id' => (int) $entryId,
                            'moved_at' => $payload['issue_at'] ?? now(),
                            'created_by' => $authUser->id,
                        ]
                    );
                }

                if ($entryType === 'PURCHASE' && $unitCost > 0) {
                    DB::table('inventory.products')
                        ->where('id', $productId)
                        ->where('company_id', $companyId)
                        ->update([
                            'cost_price' => $unitCost,
                        ]);
                }
            }

            return [
                'id' => (int) $entryId,
                'entry_type' => $entryType,
                'warehouse_id' => $warehouseId,
                'status' => $entryStatus,
                'items' => count($payload['items']),
                'metadata' => $purchaseTaxMetadata,
            ];
        });
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return [
                'complexity_mode' => 'BASIC',
                'inventory_mode' => 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => 'MANUAL',
                'enable_inventory_pro' => false,
                'enable_lot_tracking' => false,
                'enable_expiry_tracking' => false,
                'enable_advanced_reporting' => false,
                'enable_graphical_dashboard' => false,
                'enable_location_control' => false,
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => false,
            ];
        }

        return [
            'complexity_mode' => (string) ($row->complexity_mode ?? 'BASIC'),
            'inventory_mode' => (string) ($row->inventory_mode ?? 'KARDEX_SIMPLE'),
            'lot_outflow_strategy' => (string) ($row->lot_outflow_strategy ?? 'MANUAL'),
            'enable_inventory_pro' => (bool) ($row->enable_inventory_pro ?? false),
            'enable_lot_tracking' => (bool) ($row->enable_lot_tracking ?? false),
            'enable_expiry_tracking' => (bool) ($row->enable_expiry_tracking ?? false),
            'enable_advanced_reporting' => (bool) ($row->enable_advanced_reporting ?? false),
            'enable_graphical_dashboard' => (bool) ($row->enable_graphical_dashboard ?? false),
            'enable_location_control' => (bool) ($row->enable_location_control ?? false),
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
            'enforce_lot_for_tracked' => (bool) $row->enforce_lot_for_tracked,
        ];
    }

    private function applyCurrentStockDelta(int $companyId, int $warehouseId, int $productId, float $delta, bool $allowNegativeStock): void
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;

        if (!array_key_exists($projectionKey, $this->stockProjection)) {
            $row = DB::table('inventory.current_stock')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();

            $this->stockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        $current = $this->stockProjection[$projectionKey];
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for product #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }

    private function applyLotStockDelta(int $companyId, int $warehouseId, int $productId, int $lotId, float $delta, bool $allowNegativeStock): void
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;

        if (!array_key_exists($projectionKey, $this->lotStockProjection)) {
            $row = DB::table('inventory.current_stock_by_lot')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('lot_id', $lotId)
                ->first();

            $this->lotStockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        $current = $this->lotStockProjection[$projectionKey];
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for lot #' . $lotId);
        }

        $this->lotStockProjection[$projectionKey] = round($next, 8);
    }

    private function ensureStockEntriesTables(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.stock_entries (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                branch_id BIGINT NULL,
                warehouse_id BIGINT NOT NULL,
                entry_type VARCHAR(20) NOT NULL,
                reference_no VARCHAR(60) NULL,
                supplier_reference VARCHAR(120) NULL,
                issue_at TIMESTAMPTZ NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'APPLIED\',
                notes VARCHAR(300) NULL,
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                created_at TIMESTAMPTZ NULL,
                updated_at TIMESTAMPTZ NULL
            )'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS stock_entries_company_issue_idx
                ON inventory.stock_entries (company_id, issue_at DESC, id DESC)'
        );

        DB::statement('ALTER TABLE inventory.stock_entries ADD COLUMN IF NOT EXISTS payment_method_id BIGINT NULL');
        DB::statement('ALTER TABLE inventory.stock_entries ADD COLUMN IF NOT EXISTS metadata JSONB NULL');

        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.stock_entry_items (
                id BIGSERIAL PRIMARY KEY,
                entry_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                lot_id BIGINT NULL,
                qty NUMERIC(18,8) NOT NULL,
                unit_cost NUMERIC(18,8) NOT NULL DEFAULT 0,
                notes VARCHAR(200) NULL,
                created_at TIMESTAMPTZ NULL
            )'
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS stock_entry_items_entry_idx
                ON inventory.stock_entry_items (entry_id)'
        );

        DB::statement('ALTER TABLE inventory.stock_entry_items ADD COLUMN IF NOT EXISTS tax_category_id BIGINT NULL');
        DB::statement('ALTER TABLE inventory.stock_entry_items ADD COLUMN IF NOT EXISTS tax_rate NUMERIC(8,4) NOT NULL DEFAULT 0');
    }

    private function normalizePurchaseTributaryMetadata(array $rawMetadata, int $companyId, $branchId, array $items): ?array
    {
        $hasDetraccion = !empty($rawMetadata['has_detraccion']);
        $hasRetencion = !empty($rawMetadata['has_retencion']);
        $hasPercepcion = !empty($rawMetadata['has_percepcion']);

        $selected = ($hasDetraccion ? 1 : 0) + ($hasRetencion ? 1 : 0) + ($hasPercepcion ? 1 : 0);
        if ($selected > 1) {
            throw new \RuntimeException('Solo se puede aplicar una condicion tributaria por compra.');
        }
        if ($selected === 0) {
            return null;
        }

        $grandTotal = 0.0;
        foreach ($items as $item) {
            $qty = (float) ($item['qty'] ?? 0);
            $unitCost = (float) ($item['unit_cost'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);
            $subtotal = $qty * $unitCost;
            $taxAmount = $subtotal * ($taxRate / 100);
            $grandTotal += $subtotal + $taxAmount;
        }
        $grandTotal = round($grandTotal, 2);

        $metadata = [
            'has_detraccion' => $hasDetraccion,
            'has_retencion' => $hasRetencion,
            'has_percepcion' => $hasPercepcion,
        ];

        if ($hasDetraccion) {
            if (!($this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED')
                || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_DETRACCION_ENABLED'))) {
                throw new \RuntimeException('Las detracciones no estan habilitadas para compras.');
            }
            if (!$this->tableExists('master.detraccion_service_codes')) {
                throw new \RuntimeException('No existe el catalogo de codigos de detraccion.');
            }

            $detraccionCode = trim((string) ($rawMetadata['detraccion_service_code'] ?? ''));
            if ($detraccionCode === '') {
                throw new \RuntimeException('Debes seleccionar un codigo de detraccion.');
            }

            $serviceRow = DB::table('master.detraccion_service_codes')
                ->where('code', $detraccionCode)
                ->where('is_active', 1)
                ->first();

            if (!$serviceRow) {
                throw new \RuntimeException('Codigo de detraccion invalido: ' . $detraccionCode);
            }

            $detraccionRate = (float) ($serviceRow->rate_percent ?? 0);
            $detraccionAmount = round($grandTotal * $detraccionRate / 100, 2);
            $detraccionAccount = $this->resolveFeatureAccountInfo($companyId, $branchId, 'PURCHASES_DETRACCION_ENABLED', 'DETRACCION');

            $metadata['detraccion_service_code'] = $detraccionCode;
            $metadata['detraccion_service_name'] = (string) ($serviceRow->name ?? '');
            $metadata['detraccion_rate_percent'] = $detraccionRate;
            $metadata['detraccion_amount'] = $detraccionAmount;
            if ($detraccionAccount) {
                $metadata['detraccion_account_number'] = (string) ($detraccionAccount['account_number'] ?? '');
                $metadata['detraccion_bank_name'] = (string) ($detraccionAccount['bank_name'] ?? '');
            }
        }

        if ($hasRetencion) {
            $retencionScope = strtoupper(trim((string) ($rawMetadata['retencion_scope'] ?? '')));
            if (!in_array($retencionScope, ['COMPRADOR', 'PROVEEDOR'], true)) {
                $retencionScope = 'COMPRADOR';
            }

            $retencionFeatureCode = $retencionScope === 'COMPRADOR'
                ? 'PURCHASES_RETENCION_COMPRADOR_ENABLED'
                : 'PURCHASES_RETENCION_PROVEEDOR_ENABLED';

            if (!($this->isFeatureEnabled($companyId, $branchId, $retencionFeatureCode)
                || $this->isCommerceFeatureEnabled($companyId, $retencionFeatureCode))) {
                throw new \RuntimeException('La retencion seleccionada no esta habilitada para compras.');
            }

            $retencionTypes = $this->resolveRetencionTypesForFeature($companyId, $branchId, $retencionFeatureCode);
            $retencionTypeCode = strtoupper(trim((string) ($rawMetadata['retencion_type_code'] ?? '')));
            if ($retencionTypeCode === '') {
                $retencionTypeCode = (string) ($retencionTypes[0]['code'] ?? '');
            }

            $selectedRetencionType = collect($retencionTypes)->first(function ($row) use ($retencionTypeCode) {
                return strtoupper((string) ($row['code'] ?? '')) === $retencionTypeCode;
            });
            if (!$selectedRetencionType) {
                throw new \RuntimeException('Tipo de retencion invalido: ' . $retencionTypeCode);
            }

            $retencionRate = (float) ($selectedRetencionType['rate_percent'] ?? 3.00);
            $retencionAmount = round($grandTotal * $retencionRate / 100, 2);
            $retencionAccount = $this->resolveFeatureAccountInfo($companyId, $branchId, $retencionFeatureCode, 'RETENCION');

            $metadata['retencion_scope'] = $retencionScope;
            $metadata['retencion_type_code'] = $retencionTypeCode;
            $metadata['retencion_type_name'] = (string) ($selectedRetencionType['name'] ?? 'Retencion IGV');
            $metadata['retencion_rate_percent'] = $retencionRate;
            $metadata['retencion_amount'] = $retencionAmount;
            if ($retencionAccount) {
                $metadata['retencion_account_number'] = (string) ($retencionAccount['account_number'] ?? '');
                $metadata['retencion_bank_name'] = (string) ($retencionAccount['bank_name'] ?? '');
            }
        }

        if ($hasPercepcion) {
            if (!($this->isFeatureEnabled($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED')
                || $this->isCommerceFeatureEnabled($companyId, 'PURCHASES_PERCEPCION_ENABLED'))) {
                throw new \RuntimeException('La percepcion no esta habilitada para compras.');
            }

            $percepcionTypes = $this->resolvePercepcionTypes($companyId, $branchId);
            $percepcionTypeCode = strtoupper(trim((string) ($rawMetadata['percepcion_type_code'] ?? '')));
            if ($percepcionTypeCode === '') {
                $percepcionTypeCode = (string) ($percepcionTypes[0]['code'] ?? '');
            }

            $selectedPercepcionType = collect($percepcionTypes)->first(function ($row) use ($percepcionTypeCode) {
                return strtoupper((string) ($row['code'] ?? '')) === $percepcionTypeCode;
            });
            if (!$selectedPercepcionType) {
                throw new \RuntimeException('Tipo de percepcion invalido: ' . $percepcionTypeCode);
            }

            $percepcionRate = (float) ($selectedPercepcionType['rate_percent'] ?? 2.00);
            $percepcionAmount = round($grandTotal * $percepcionRate / 100, 2);
            $percepcionAccount = $this->resolveFeatureAccountInfo($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED', 'PERCEPCION');

            $metadata['percepcion_type_code'] = $percepcionTypeCode;
            $metadata['percepcion_type_name'] = (string) ($selectedPercepcionType['name'] ?? 'Percepcion');
            $metadata['percepcion_rate_percent'] = $percepcionRate;
            $metadata['percepcion_amount'] = $percepcionAmount;
            if ($percepcionAccount) {
                $metadata['percepcion_account_number'] = (string) ($percepcionAccount['account_number'] ?? '');
                $metadata['percepcion_bank_name'] = (string) ($percepcionAccount['bank_name'] ?? '');
            }
        }

        if ($selected > 0) {
            $operationTypes = $this->resolvePurchaseSunatOperationTypes($companyId, $branchId);
            $operationCode = strtoupper(trim((string) ($rawMetadata['sunat_operation_type_code'] ?? '')));
            if ($operationCode === '') {
                $operationCode = (string) ($operationTypes[0]['code'] ?? '0101');
            }

            $selectedOperationType = collect($operationTypes)->first(function ($row) use ($operationCode) {
                return strtoupper((string) ($row['code'] ?? '')) === $operationCode;
            });

            if (!$selectedOperationType) {
                throw new \RuntimeException('Tipo de operacion SUNAT invalido: ' . $operationCode);
            }

            $metadata['sunat_operation_type_code'] = (string) ($selectedOperationType['code'] ?? $operationCode);
            $metadata['sunat_operation_type_name'] = (string) ($selectedOperationType['name'] ?? '');
            $metadata['sunat_operation_regime'] = (string) ($selectedOperationType['regime'] ?? 'NONE');
        }

        return $metadata;
    }

    private function resolveRetencionTypesForFeature(int $companyId, $branchId, string $featureCode): array
    {
        $defaultRate = 3.00;
        $defaultType = [
            'code' => 'RET_IGV_3',
            'name' => 'Retencion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
        $configuredTypes = isset($config['retencion_types']) && is_array($config['retencion_types'])
            ? $config['retencion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePercepcionTypes(int $companyId, $branchId): array
    {
        $defaultRate = 2.00;
        $defaultType = [
            'code' => 'PERC_IGV_2',
            'name' => 'Percepcion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'PURCHASES_PERCEPCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
        $configuredTypes = isset($config['percepcion_types']) && is_array($config['percepcion_types'])
            ? $config['percepcion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePurchaseSunatOperationTypes(int $companyId, $branchId): array
    {
        $defaultRows = [
            ['code' => '0101', 'name' => 'Compra interna', 'regime' => 'NONE'],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION'],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION'],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION'],
        ];

        $featureCodes = [
            'PURCHASES_DETRACCION_ENABLED',
            'PURCHASES_RETENCION_COMPRADOR_ENABLED',
            'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
            'PURCHASES_PERCEPCION_ENABLED',
        ];

        $configuredRows = [];
        foreach ($featureCodes as $featureCode) {
            $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
            $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
            if (isset($config['sunat_operation_types']) && is_array($config['sunat_operation_types'])) {
                $configuredRows = array_merge($configuredRows, $config['sunat_operation_types']);
            }
        }

        $rows = collect($configuredRows)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }
                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $regime = strtoupper(trim((string) ($item['regime'] ?? 'NONE')));
                if (!in_array($regime, ['NONE', 'DETRACCION', 'RETENCION', 'PERCEPCION'], true)) {
                    $regime = 'NONE';
                }

                return [
                    'code' => $code,
                    'name' => $name,
                    'regime' => $regime,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : $defaultRows;
    }

    private function resolveFeatureAccountInfo(int $companyId, $branchId, string $featureCode, string $fallbackKeyword): ?array
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $accountNumber = trim((string) ($config['account_number'] ?? ''));
        if ($accountNumber !== '') {
            return [
                'bank_name' => trim((string) ($config['bank_name'] ?? '')),
                'account_number' => $accountNumber,
                'account_holder' => trim((string) ($config['account_holder'] ?? '')),
            ];
        }

        $bankAccounts = $this->resolveCompanyBankAccounts($companyId);
        $keyword = strtoupper(trim($fallbackKeyword));
        foreach ($bankAccounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountType = strtoupper(trim((string) ($account['account_type'] ?? '')));
            $number = trim((string) ($account['account_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            if ($keyword !== '' && strpos($accountType, $keyword) === false) {
                continue;
            }

            return [
                'bank_name' => trim((string) ($account['bank_name'] ?? '')),
                'account_number' => $number,
                'account_holder' => trim((string) ($account['account_holder'] ?? '')),
            ];
        }

        return null;
    }

    private function resolveCompanyBankAccounts(int $companyId): array
    {
        $row = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('bank_accounts')
            ->first();

        if (!$row || $row->bank_accounts === null) {
            return [];
        }

        $decoded = is_string($row->bank_accounts)
            ? json_decode($row->bank_accounts, true)
            : (array) $row->bank_accounts;

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function ($item) {
            return is_array($item);
        }));
    }

    private function isFeatureEnabled(int $companyId, $branchId, string $featureCode): bool
    {
        $branchEnabled = null;
        if ($branchId !== null) {
            $branchToggle = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->first();
            if ($branchToggle) {
                $branchEnabled = (bool) $branchToggle->is_enabled;
            }
        }

        if ($branchEnabled !== null) {
            return $branchEnabled;
        }

        $companyToggle = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        return $companyToggle ? (bool) $companyToggle->is_enabled : false;
    }

    private function isCommerceFeatureEnabled(int $companyId, string $featureCode): bool
    {
        $row = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        return $row ? (bool) ($row->is_enabled ?? false) : false;
    }

    private function resolveFeatureToggleRow(int $companyId, $branchId, string $featureCode)
    {
        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->first();

            if ($branchRow && (bool) ($branchRow->is_enabled ?? false)) {
                return $branchRow;
            }

            if ($companyRow && (bool) ($companyRow->is_enabled ?? false)) {
                return $companyRow;
            }

            if ($branchRow) {
                return $branchRow;
            }
        }

        return $companyRow;
    }

    private function decodeFeatureConfig($rawConfig): array
    {
        if ($rawConfig === null) {
            return [];
        }

        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        return [];
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        return isset($row->present) && (bool) $row->present;
    }

    private function tableColumns(string $qualifiedTable): array
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

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') !== false) {
            return explode('.', $qualifiedTable, 2);
        }

        return ['public', $qualifiedTable];
    }

    private function resolveTaxCategoriesTable(): ?string
    {
        foreach (['core.tax_categories', 'sales.tax_categories', 'appcfg.tax_categories'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
