<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Domain\Inventory\Repositories\InventoryProductRepositoryInterface;
use Illuminate\Support\Facades\DB;

class InventoryProductCommandRepository
{
    private array $stockProjection = [];

    public function __construct(
        private InventoryProductRepositoryInterface $inventoryProductRepository
    ) {
    }

    public function listProducts(int $companyId, string $search, $status, int $limit, bool $autocomplete): array
    {
        $search = trim($search);
        $limit = max(1, min($limit, $autocomplete ? 100 : 50000));

        return $this->inventoryProductRepository->getProducts($companyId, $search, $status, $limit, $autocomplete);
    }

    public function bulkImportProducts(int $companyId, int $userId, array $validated): array
    {
        $rows = $validated['rows'] ?? [];
        $filename = isset($validated['filename']) ? trim((string) $validated['filename']) : null;
        $defaultWarehouseCode = strtoupper(trim((string) ($validated['warehouse_code'] ?? '')));

        $defaultUnitId = $this->resolveDefaultUnitId();
        if ($defaultUnitId === null) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'No se encontró la unidad por defecto NIU (UNIDAD (BIENES)) en core.units.',
            ];
        }

        $batchId = (int) DB::table('inventory.product_import_batches')->insertGetId([
            'company_id' => $companyId,
            'imported_by' => $userId,
            'filename' => ($filename !== '' ? $filename : null),
            'total_rows' => count($rows),
            'status' => 'PROCESSING',
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $unitMap = $this->buildUnitLookupMap();

        $preparedRows = [];
        $candidateIds = [];
        $candidateSkus = [];
        $candidateBarcodes = [];
        $candidateNames = [];
        $rowWarehouseCodes = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));
            $skuRaw = trim((string) ($row['sku'] ?? ''));
            $barcodeRaw = trim((string) ($row['barcode'] ?? ''));
            $unitCodeRaw = trim((string) ($row['unit_code'] ?? ''));

            $sku = $skuRaw !== '' ? strtoupper($skuRaw) : null;
            $barcode = $barcodeRaw !== '' ? $barcodeRaw : null;
            $unitId = $this->resolveUnitIdFromCode($unitCodeRaw, $unitMap, $defaultUnitId);
            $nature = $this->normalizeProductNature((string) ($row['product_nature'] ?? ''));
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $rowWarehouseCode = strtoupper(trim((string) ($row['warehouse_code'] ?? '')));

            $preparedRows[] = [
                'row_number' => $rowNumber,
                'row' => $row,
                'id' => $id,
                'name' => $name,
                'sku' => $sku,
                'barcode' => $barcode,
                'unit_id' => $unitId,
                'nature' => $nature,
                'row_warehouse_code' => $rowWarehouseCode,
            ];

            if ($id > 0) {
                $candidateIds[$id] = true;
            }
            if ($sku !== null) {
                $candidateSkus[$sku] = true;
            }
            if ($barcode !== null) {
                $candidateBarcodes[$barcode] = true;
            }
            if ($name !== '' && $sku === null && $barcode === null) {
                $candidateNames[strtoupper(trim($name))] = true;
            }
            if ($rowWarehouseCode !== '') {
                $rowWarehouseCodes[$rowWarehouseCode] = true;
            }
        }

        $existingLookups = $this->prefetchExistingProductsForImport(
            $companyId,
            array_keys($candidateIds),
            array_keys($candidateSkus),
            array_keys($candidateBarcodes),
            array_keys($candidateNames)
        );

        $warehouseCache = $this->buildWarehouseLookupMap($companyId, array_keys($rowWarehouseCodes));

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $itemLogs = [];
        $seenRowKeys = [];
        $ledgerRows = [];
        $stockApplied = 0;
        $stockSkipped = 0;

        if ($defaultWarehouseCode !== '') {
            $resolvedTopLevelWarehouseId = $this->resolveWarehouseIdFromCode($companyId, $defaultWarehouseCode, $warehouseCache);
            if ($resolvedTopLevelWarehouseId === null) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'warehouse_code por defecto no existe o está inactivo.',
                ];
            }

            $defaultWarehouse = [
                'id' => $resolvedTopLevelWarehouseId,
                'code' => $defaultWarehouseCode,
            ];
        } else {
            $defaultWarehouse = $this->resolveDefaultWarehouseForImport($companyId);
        }

        DB::transaction(function () use (
            $preparedRows,
            $batchId,
            $companyId,
            $userId,
            $defaultWarehouse,
            &$existingLookups,
            &$warehouseCache,
            &$created,
            &$updated,
            &$skipped,
            &$errors,
            &$itemLogs,
            &$seenRowKeys,
            &$ledgerRows,
            &$stockApplied,
            &$stockSkipped
        ) {
        foreach ($preparedRows as $preparedRow) {
            $row = $preparedRow['row'];
            $rowNumber = (int) $preparedRow['row_number'];
            $name = (string) $preparedRow['name'];

            if ($name === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Nombre es obligatorio.'];
                $itemLogs[] = [
                    'batch_id' => $batchId,
                    'row_number' => $rowNumber,
                    'action_status' => 'SKIPPED',
                    'product_id' => null,
                    'sku' => null,
                    'barcode' => null,
                    'name' => null,
                    'message' => 'Nombre es obligatorio.',
                    'created_at' => now(),
                ];
                continue;
            }

            $sku = $preparedRow['sku'];
            $barcode = $preparedRow['barcode'];
            $unitId = (int) $preparedRow['unit_id'];
            $nature = (string) $preparedRow['nature'];

            $payload = [
                'unit_id' => $unitId,
                'product_nature' => $nature,
                'sku' => $sku,
                'barcode' => $barcode,
                'sunat_code' => $this->nullIfBlank((string) ($row['sunat_code'] ?? '')),
                'name' => $name,
                'sale_price' => $this->normalizeNumeric($row['sale_price'] ?? null, 0),
                'cost_price' => $this->normalizeNumeric($row['cost_price'] ?? null, 0),
                'is_stockable' => $this->normalizeBoolean($row['is_stockable'] ?? null, true),
                'lot_tracking' => $this->normalizeBoolean($row['lot_tracking'] ?? null, false),
                'has_expiration' => $this->normalizeBoolean($row['has_expiration'] ?? null, false),
                'status' => $this->normalizeBoolean($row['status'] ?? null, true) ? 1 : 0,
            ];

            $id = (int) $preparedRow['id'];
            if ($id > 0) {
                $rowUniqueKey = 'ID:' . $id;
            } elseif ($sku !== null) {
                $rowUniqueKey = 'SKU:' . $sku;
            } elseif ($barcode !== null) {
                $rowUniqueKey = 'BARCODE:' . $barcode;
            } else {
                $rowUniqueKey = 'NAME:' . strtoupper(trim($name)) . '|UNIT:' . (string) $unitId . '|NATURE:' . $nature;
            }

            if (isset($seenRowKeys[$rowUniqueKey])) {
                $skipped++;
                $duplicateMessage = 'Fila duplicada dentro del archivo (misma clave de importación).';
                $errors[] = ['row' => $rowNumber, 'message' => $duplicateMessage];
                $itemLogs[] = [
                    'batch_id' => $batchId,
                    'row_number' => $rowNumber,
                    'action_status' => 'SKIPPED',
                    'product_id' => null,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'name' => $name,
                    'message' => $duplicateMessage,
                    'created_at' => now(),
                ];
                continue;
            }

            $seenRowKeys[$rowUniqueKey] = true;
            $existing = null;

            if ($id > 0) {
                $existingId = $existingLookups['by_id'][$id] ?? null;
                $existing = $existingId !== null ? (object) ['id' => $existingId] : null;
            }

            if (!$existing && $sku !== null) {
                $existingId = $existingLookups['by_sku'][$sku] ?? null;
                $existing = $existingId !== null ? (object) ['id' => $existingId] : null;
            }

            if (!$existing && $barcode !== null) {
                $existingId = $existingLookups['by_barcode'][$barcode] ?? null;
                $existing = $existingId !== null ? (object) ['id' => $existingId] : null;
            }

            if (!$existing && $sku === null && $barcode === null) {
                $compositeKey = $this->buildProductCompositeLookupKey($name, $unitId, $nature);
                $existingId = $existingLookups['by_composite'][$compositeKey] ?? null;
                $existing = $existingId !== null ? (object) ['id' => $existingId] : null;
            }

            if ($existing) {
                $duplicateMessage = 'Producto duplicado: ya existe en el catalogo de la empresa.';
                $errors[] = ['row' => $rowNumber, 'message' => $duplicateMessage];
                $itemLogs[] = [
                    'batch_id' => $batchId,
                    'row_number' => $rowNumber,
                    'action_status' => 'SKIPPED',
                    'product_id' => (int) $existing->id,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'name' => $name,
                    'message' => $duplicateMessage,
                    'created_at' => now(),
                ];

                $skipped++;
                continue;
            }

            $newProductId = (int) DB::table('inventory.products')->insertGetId(array_merge($payload, [
                'company_id' => $companyId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]));

            $existingLookups['by_id'][$newProductId] = $newProductId;
            if ($sku !== null) {
                $existingLookups['by_sku'][$sku] = $newProductId;
            }
            if ($barcode !== null) {
                $existingLookups['by_barcode'][$barcode] = $newProductId;
            }
            $existingLookups['by_composite'][$this->buildProductCompositeLookupKey($name, $unitId, $nature)] = $newProductId;

            $stockMessage = null;
            $initialQtyRaw = trim((string) ($row['initial_qty'] ?? ''));
            $initialQty = $this->normalizeNumeric($row['initial_qty'] ?? null, 0);
            if ($initialQtyRaw !== '') {
                if ($initialQty > 0 && (bool) $payload['is_stockable']) {
                    $warehouseId = null;
                    $warehouseCodeUsed = null;
                    $usedDefaultByInvalidRowWarehouse = false;
                    $rowWarehouseCode = (string) $preparedRow['row_warehouse_code'];
                    if ($rowWarehouseCode !== '') {
                        $warehouseId = $warehouseCache[$rowWarehouseCode] ?? null;
                        if ($warehouseId !== null) {
                            $warehouseCodeUsed = $rowWarehouseCode;
                        } elseif ($defaultWarehouse !== null) {
                            $warehouseId = (int) $defaultWarehouse['id'];
                            $warehouseCodeUsed = (string) $defaultWarehouse['code'];
                            $usedDefaultByInvalidRowWarehouse = true;
                        }
                    } elseif ($defaultWarehouse !== null) {
                        $warehouseId = (int) $defaultWarehouse['id'];
                        $warehouseCodeUsed = (string) $defaultWarehouse['code'];
                    }

                    if ($warehouseId === null) {
                        $stockSkipped++;
                        $stockMessage = $rowWarehouseCode !== ''
                            ? 'Stock inicial no aplicado: warehouse_code no existe o inactivo.'
                            : 'Stock inicial no aplicado: no existe almacén activo para usar como principal.';
                        $errors[] = ['row' => $rowNumber, 'message' => $stockMessage];
                    } else {
                        $ledgerRows[] = [
                            'company_id' => $companyId,
                            'warehouse_id' => $warehouseId,
                            'product_id' => $newProductId,
                            'lot_id' => null,
                            'movement_type' => 'IN',
                            'quantity' => round($initialQty, 3),
                            'unit_cost' => round($this->normalizeNumeric($row['initial_cost'] ?? $payload['cost_price'] ?? 0, 0), 4),
                            'ref_type' => 'PRODUCT_IMPORT',
                            'ref_id' => $batchId,
                            'notes' => 'Importacion masiva lote #' . $batchId,
                            'moved_at' => now(),
                            'created_by' => $userId,
                        ];
                        $stockApplied++;
                        $stockMessage = $usedDefaultByInvalidRowWarehouse
                            ? ('Stock inicial aplicado en almacén ' . $warehouseCodeUsed . ' (warehouse_code inválido en fila).')
                            : ('Stock inicial aplicado en almacén ' . $warehouseCodeUsed . '.');
                    }
                } elseif ($initialQty <= 0) {
                    $stockSkipped++;
                    $stockMessage = 'Stock inicial no aplicado: initial_qty debe ser mayor a 0.';
                    $errors[] = ['row' => $rowNumber, 'message' => $stockMessage];
                }
            }

            $itemLogs[] = [
                'batch_id' => $batchId,
                'row_number' => $rowNumber,
                'action_status' => 'CREATED',
                'product_id' => $newProductId,
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => $name,
                'message' => $stockMessage ? ('Producto creado. ' . $stockMessage) : 'Producto creado.',
                'created_at' => now(),
            ];

            $created++;
        }

        if (!empty($ledgerRows)) {
            foreach (array_chunk($ledgerRows, 500) as $chunk) {
                DB::table('inventory.inventory_ledger')->insert($chunk);
            }
        }

        if (!empty($itemLogs)) {
            foreach (array_chunk($itemLogs, 500) as $chunk) {
                DB::table('inventory.product_import_batch_items')->insert($chunk);
            }
        }

        $finalStatus = ($skipped > 0 || count($errors) > 0) ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED';
        DB::table('inventory.product_import_batches')
            ->where('id', $batchId)
            ->update([
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count' => count($errors),
                'errors_json' => count($errors) > 0 ? json_encode(array_slice($errors, 0, 300)) : null,
                'status' => $finalStatus,
                'finished_at' => now(),
            ]);
            });

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'message' => 'Importación masiva procesada.',
                'batch_id' => $batchId,
                'summary' => [
                    'total' => count($rows),
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'errors' => count($errors),
                    'stock_applied' => $stockApplied,
                    'stock_skipped' => $stockSkipped,
                ],
                'errors' => array_slice($errors, 0, 300),
            ],
        ];
    }

    public function bulkUpdateProductStock(int $companyId, int $userId, array $validated): array
    {
        $rows = $validated['rows'] ?? [];
        $mode = strtolower((string) ($validated['mode'] ?? 'add'));
        $filename = isset($validated['filename']) ? trim((string) $validated['filename']) : null;
        $defaultWarehouseCode = strtoupper(trim((string) ($validated['warehouse_code'] ?? '')));

        $defaultWarehouse = null;
        $warehouseCache = [];
        if ($defaultWarehouseCode !== '') {
            $resolvedWarehouseId = $this->resolveWarehouseIdFromCode($companyId, $defaultWarehouseCode, $warehouseCache);
            if ($resolvedWarehouseId === null) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'warehouse_code por defecto no existe o está inactivo.',
                ];
            }

            $defaultWarehouse = [
                'id' => $resolvedWarehouseId,
                'code' => $defaultWarehouseCode,
            ];
        } else {
            $defaultWarehouse = $this->resolveDefaultWarehouseForImport($companyId);
        }

        $batchId = (int) DB::table('inventory.stock_update_batches')->insertGetId([
            'company_id' => $companyId,
            'updated_by' => $userId,
            'filename' => $filename !== '' ? $filename : null,
            'mode' => strtoupper($mode),
            'total_rows' => count($rows),
            'status' => 'PROCESSING',
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $productIds = [];
        $productSkus = [];
        $normalizedRows = [];
        $seenKeys = [];
        $warehouseCodes = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
            $qty = $this->normalizeNumeric($row['qty'] ?? null, 0);
            $rowWarehouseCode = strtoupper(trim((string) ($row['warehouse_code'] ?? '')));

            $key = $id > 0 ? 'ID:' . $id : ($sku !== '' ? 'SKU:' . $sku : 'ROW:' . $rowNumber);
            if (isset($seenKeys[$key])) {
                $normalizedRows[] = [
                    'row_number' => $rowNumber,
                    'status' => 'OMITTED',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'barcode' => null,
                    'name' => null,
                    'message' => 'Fila duplicada dentro del archivo.',
                    'qty' => $qty,
                    'applied_delta' => 0,
                    'created_at' => now(),
                ];
                continue;
            }
            $seenKeys[$key] = true;

            $normalizedRows[] = [
                'row_number' => $rowNumber,
                'status' => 'PENDING',
                'product_id' => $id > 0 ? $id : null,
                'sku' => $sku !== '' ? $sku : null,
                'barcode' => null,
                'name' => null,
                'message' => null,
                'qty' => $qty,
                'applied_delta' => 0,
                'created_at' => now(),
                '_row' => $row,
                '_warehouse_code' => $rowWarehouseCode,
            ];

            if ($id > 0) {
                $productIds[$id] = true;
            } elseif ($sku !== '') {
                $productSkus[$sku] = true;
            }

            if ($rowWarehouseCode !== '') {
                $warehouseCodes[$rowWarehouseCode] = true;
            }
        }

        $warehouseCache = $this->buildWarehouseLookupMap($companyId, array_keys($warehouseCodes));

        $productsById = collect();
        if (!empty($productIds)) {
            $productsById = DB::table('inventory.products')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereIn('id', array_keys($productIds))
                ->select('id', 'sku', 'barcode', 'name', 'is_stockable', 'status')
                ->get()
                ->keyBy('id');
        }

        $productsBySku = collect();
        if (!empty($productSkus)) {
            $productsBySku = DB::table('inventory.products')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereNotNull('sku')
                ->whereIn(DB::raw("UPPER(TRIM(COALESCE(sku, '')))") , array_keys($productSkus))
                ->select('id', 'sku', 'barcode', 'name', 'is_stockable', 'status')
                ->get()
                ->keyBy(function ($row) {
                    return strtoupper(trim((string) $row->sku));
                });
        }

        $warehouseIdsForSeed = [];
        if ($defaultWarehouse !== null) {
            $warehouseIdsForSeed[(int) $defaultWarehouse['id']] = true;
        }
        foreach ($warehouseCache as $warehouseId) {
            if ($warehouseId !== null) {
                $warehouseIdsForSeed[(int) $warehouseId] = true;
            }
        }

        $productIdsForSeed = [];
        foreach ($productsById as $product) {
            $productIdsForSeed[(int) $product->id] = true;
        }
        foreach ($productsBySku as $product) {
            $productIdsForSeed[(int) $product->id] = true;
        }

        $this->seedStockProjection($companyId, array_keys($warehouseIdsForSeed), array_keys($productIdsForSeed));

        $createdProducts = 0;
        $updated = 0;
        $omitted = 0;
        $errors = [];
        $batchItems = [];
        $ledgerRows = [];
        $ledgerInserted = 0;

        DB::transaction(function () use (
            $normalizedRows,
            $mode,
            $companyId,
            $batchId,
            $userId,
            $defaultWarehouse,
            $warehouseCache,
            $productsById,
            $productsBySku,
            &$createdProducts,
            &$updated,
            &$omitted,
            &$errors,
            &$batchItems,
            &$ledgerRows,
            &$ledgerInserted
        ) {
            foreach ($normalizedRows as $rowState) {
                if (($rowState['status'] ?? '') === 'OMITTED') {
                    $omitted++;
                    $batchItems[] = [
                        'batch_id' => $batchId,
                        'row_number' => $rowState['row_number'],
                        'action_status' => 'OMITTED',
                        'product_id' => null,
                        'sku' => $rowState['sku'],
                        'barcode' => null,
                        'name' => null,
                        'message' => $rowState['message'],
                        'created_at' => now(),
                    ];
                    continue;
                }

                $row = $rowState['_row'];
                $rowNumber = (int) $rowState['row_number'];
                $id = (int) ($rowState['product_id'] ?? 0);
                $sku = (string) ($rowState['sku'] ?? '');
                $qty = round((float) ($rowState['qty'] ?? 0), 8);

                $product = null;
                if ($id > 0) {
                    $product = $productsById->get($id);
                } elseif ($sku !== '') {
                    $product = $productsBySku->get($sku);
                }

                if (!$product) {
                    if ($id > 0) {
                        $omitted++;
                        $message = 'Producto no encontrado por ID.';
                        $errors[] = ['row' => $rowNumber, 'message' => $message];
                        $batchItems[] = [
                            'batch_id' => $batchId,
                            'row_number' => $rowNumber,
                            'action_status' => 'OMITTED',
                            'product_id' => $id,
                            'sku' => $sku !== '' ? $sku : null,
                            'barcode' => null,
                            'name' => null,
                            'message' => $message,
                            'created_at' => now(),
                        ];
                        continue;
                    }

                    $newProductId = (int) DB::table('inventory.products')->insertGetId([
                        'company_id' => $companyId,
                        'sku' => $sku,
                        'name' => $sku,
                        'product_nature' => 'PRODUCT',
                        'unit_id' => null,
                        'is_stockable' => true,
                        'lot_tracking' => false,
                        'has_expiration' => false,
                        'status' => 1,
                        'sale_price' => 0,
                        'cost_price' => 0,
                        'seller_commission_percent' => 0,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);

                    $product = (object) [
                        'id' => $newProductId,
                        'sku' => $sku,
                        'barcode' => null,
                        'name' => $sku,
                        'is_stockable' => true,
                        'status' => 1,
                    ];

                    $productsBySku->put($sku, $product);
                    $createdProducts++;
                }

                if ((int) ($product->status ?? 0) !== 1) {
                    $omitted++;
                    $message = 'Producto inactivo.';
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                    $batchItems[] = [
                        'batch_id' => $batchId,
                        'row_number' => $rowNumber,
                        'action_status' => 'OMITTED',
                        'product_id' => (int) $product->id,
                        'sku' => (string) ($product->sku ?? null),
                        'barcode' => (string) ($product->barcode ?? null),
                        'name' => (string) ($product->name ?? null),
                        'message' => $message,
                        'created_at' => now(),
                    ];
                    continue;
                }

                if (!(bool) ($product->is_stockable ?? false)) {
                    $omitted++;
                    $message = 'Producto no es stockeable.';
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                    $batchItems[] = [
                        'batch_id' => $batchId,
                        'row_number' => $rowNumber,
                        'action_status' => 'OMITTED',
                        'product_id' => (int) $product->id,
                        'sku' => (string) ($product->sku ?? null),
                        'barcode' => (string) ($product->barcode ?? null),
                        'name' => (string) ($product->name ?? null),
                        'message' => $message,
                        'created_at' => now(),
                    ];
                    continue;
                }

                $rowWarehouseCode = (string) ($rowState['_warehouse_code'] ?? '');
                $warehouseId = null;
                $warehouseCodeUsed = null;
                if ($rowWarehouseCode !== '') {
                    $warehouseId = $warehouseCache[$rowWarehouseCode] ?? null;
                    if ($warehouseId !== null) {
                        $warehouseCodeUsed = $rowWarehouseCode;
                    }
                }
                if ($warehouseId === null && $defaultWarehouse !== null) {
                    $warehouseId = (int) $defaultWarehouse['id'];
                    $warehouseCodeUsed = (string) ($defaultWarehouse['code'] ?? '');
                }

                if ($warehouseId === null) {
                    $omitted++;
                    $message = $rowWarehouseCode !== ''
                        ? 'warehouse_code no existe o está inactivo.'
                        : 'No existe almacén activo para usar como destino.';
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                    $batchItems[] = [
                        'batch_id' => $batchId,
                        'row_number' => $rowNumber,
                        'action_status' => 'OMITTED',
                        'product_id' => (int) $product->id,
                        'sku' => (string) ($product->sku ?? null),
                        'barcode' => (string) ($product->barcode ?? null),
                        'name' => (string) ($product->name ?? null),
                        'message' => $message,
                        'created_at' => now(),
                    ];
                    continue;
                }

                $currentStock = $this->getProjectedCurrentStock($companyId, $warehouseId, (int) $product->id);

                $desiredStock = $mode === 'replace'
                    ? $qty
                    : $currentStock + $qty;
                $delta = round($desiredStock - $currentStock, 8);

                if (abs($delta) < 0.00000001) {
                    $omitted++;
                    $message = $mode === 'replace'
                        ? 'Sin cambios: el stock ya coincide con el valor solicitado.'
                        : 'Sin cambios: el delta es cero.';
                    $batchItems[] = [
                        'batch_id' => $batchId,
                        'row_number' => $rowNumber,
                        'action_status' => 'OMITTED',
                        'product_id' => (int) $product->id,
                        'sku' => (string) ($product->sku ?? null),
                        'barcode' => (string) ($product->barcode ?? null),
                        'name' => (string) ($product->name ?? null),
                        'message' => $message,
                        'created_at' => now(),
                    ];
                    continue;
                }

                $movementType = $delta > 0 ? 'IN' : 'OUT';
                $unitCost = $this->normalizeNumeric($row['unit_cost'] ?? null, 0);
                $note = trim((string) ($row['note'] ?? ''));
                $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

                $allowNegativeStock = false;
                $this->applyCurrentStockDelta(
                    $companyId,
                    $warehouseId,
                    (int) $product->id,
                    $delta,
                    $allowNegativeStock
                );

                $ledgerRows[] = [
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => (int) $product->id,
                    'lot_id' => null,
                    'movement_type' => $movementType,
                    'quantity' => round(abs($delta), 8),
                    'unit_cost' => round($unitCost, 8),
                    'ref_type' => 'STOCK_BULK_UPDATE',
                    'ref_id' => $batchId,
                    'notes' => $note !== '' ? $note : ('Actualización masiva de stock lote #' . $batchId),
                    'moved_at' => now(),
                    'created_by' => $userId,
                ];

                if (!empty($metadata)) {
                    $batchItems[] = [
                        'batch_id' => $batchId,
                        'row_number' => $rowNumber,
                        'action_status' => 'APPLIED',
                        'product_id' => (int) $product->id,
                        'sku' => (string) ($product->sku ?? null),
                        'barcode' => (string) ($product->barcode ?? null),
                        'name' => (string) ($product->name ?? null),
                        'warehouse_id' => $warehouseId,
                        'warehouse_code' => $warehouseCodeUsed,
                        'mode' => strtoupper($mode),
                        'requested_qty' => round($qty, 8),
                        'current_stock' => round($currentStock, 8),
                        'applied_delta' => round($delta, 8),
                        'new_stock' => round($desiredStock, 8),
                        'message' => $delta > 0 ? 'Stock incrementado.' : 'Stock reemplazado/reducido.',
                        'metadata' => json_encode($metadata),
                        'created_at' => now(),
                    ];
                } else {
                    $batchItems[] = [
                        'batch_id' => $batchId,
                        'row_number' => $rowNumber,
                        'action_status' => 'APPLIED',
                        'product_id' => (int) $product->id,
                        'sku' => (string) ($product->sku ?? null),
                        'barcode' => (string) ($product->barcode ?? null),
                        'name' => (string) ($product->name ?? null),
                        'warehouse_id' => null,
                        'warehouse_code' => null,
                        'mode' => null,
                        'requested_qty' => null,
                        'current_stock' => null,
                        'applied_delta' => null,
                        'new_stock' => null,
                        'message' => $delta > 0 ? 'Stock incrementado.' : 'Stock reemplazado/reducido.',
                        'metadata' => null,
                        'created_at' => now(),
                    ];
                }

                $updated++;
                $ledgerInserted++;
            }

            if (!empty($batchItems)) {
                foreach (array_chunk($batchItems, 500) as $chunk) {
                    DB::table('inventory.stock_update_batch_items')->insert($chunk);
                }
            }

            if (!empty($ledgerRows)) {
                foreach (array_chunk($ledgerRows, 500) as $chunk) {
                    DB::table('inventory.inventory_ledger')->insert($chunk);
                }
            }

            DB::table('inventory.stock_update_batches')
                ->where('id', $batchId)
                ->update([
                    'updated_count' => $updated,
                    'omitted_count' => $omitted,
                    'error_count' => count($errors),
                    'status' => ($omitted > 0 || count($errors) > 0) ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED',
                    'finished_at' => now(),
                ]);
        });

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'message' => 'Actualización masiva de stock procesada.',
                'batch_id' => $batchId,
                'summary' => [
                    'total' => count($rows),
                    'applied' => $updated,
                    'created_products' => $createdProducts,
                    'omitted' => $omitted,
                    'errors' => count($errors),
                    'ledger_rows' => $ledgerInserted,
                    'mode' => $mode,
                ],
                'errors' => array_slice($errors, 0, 300),
            ],
        ];
    }

    public function createProduct(int $companyId, array $payload, ?int $userId = null): array
    {
        foreach ([
            ['line_id', 'inventory.product_lines'],
            ['brand_id', 'inventory.product_brands'],
            ['location_id', 'inventory.product_locations'],
            ['warranty_id', 'inventory.product_warranties'],
        ] as $masterRule) {
            [$field, $table] = $masterRule;
            if (!empty($payload[$field]) && !$this->productMasterExists($table, (int) $payload[$field], $companyId)) {
                return ['ok' => false, 'status' => 422, 'message' => 'Invalid ' . $field];
            }
        }

        $normalizedSku = isset($payload['sku']) ? strtoupper(trim((string) $payload['sku'])) : null;
        if ($normalizedSku === '') {
            $normalizedSku = null;
        }

        $normalizedBarcode = isset($payload['barcode']) ? trim((string) $payload['barcode']) : null;
        if ($normalizedBarcode === '') {
            $normalizedBarcode = null;
        }

        $normalizedName = trim((string) $payload['name']);
        $normalizedNature = $this->normalizeProductNature((string) ($payload['product_nature'] ?? 'PRODUCT'));
        $normalizedUnitId = isset($payload['unit_id']) ? (int) $payload['unit_id'] : null;

        $duplicate = $this->findExistingActiveProduct(
            $companyId,
            $normalizedSku,
            $normalizedBarcode,
            $normalizedName,
            $normalizedUnitId,
            $normalizedNature
        );

        if ($duplicate) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'El producto ya existe y no se puede registrar duplicado.',
                'duplicate_product_id' => (int) $duplicate->id,
            ];
        }

        $initialQty = round(max(0, $this->normalizeNumeric($payload['initial_qty'] ?? null, 0)), 3);
        $initialCost = round(max(0, $this->normalizeNumeric($payload['initial_cost'] ?? ($payload['cost_price'] ?? 0), 0)), 4);
        $stockNote = $this->nullIfBlank((string) ($payload['stock_note'] ?? ''));
        $warehouseId = null;

        if ($initialQty > 0) {
            if (!empty($payload['warehouse_id'])) {
                $warehouseRow = DB::table('inventory.warehouses')
                    ->where('company_id', $companyId)
                    ->where('status', 1)
                    ->where('id', (int) $payload['warehouse_id'])
                    ->select('id')
                    ->first();

                if (!$warehouseRow) {
                    return ['ok' => false, 'status' => 422, 'message' => 'warehouse_id no existe o está inactivo.'];
                }

                $warehouseId = (int) $warehouseRow->id;
            } else {
                $warehouseCode = strtoupper(trim((string) ($payload['warehouse_code'] ?? '')));
                if ($warehouseCode !== '') {
                    $warehouseCache = [];
                    $warehouseId = $this->resolveWarehouseIdFromCode($companyId, $warehouseCode, $warehouseCache);
                    if ($warehouseId === null) {
                        return ['ok' => false, 'status' => 422, 'message' => 'warehouse_code no existe o está inactivo.'];
                    }
                } else {
                    $defaultWarehouse = $this->resolveDefaultWarehouseForImport($companyId);
                    $warehouseId = $defaultWarehouse['id'] ?? null;
                    if (!$warehouseId) {
                        return ['ok' => false, 'status' => 422, 'message' => 'No existe almacén activo para aplicar stock inicial.'];
                    }
                }
            }
        }

        $id = DB::transaction(function () use (
            $companyId,
            $payload,
            $normalizedUnitId,
            $normalizedNature,
            $normalizedSku,
            $normalizedBarcode,
            $normalizedName,
            $initialQty,
            $initialCost,
            $stockNote,
            $warehouseId,
            $userId
        ) {
            $newId = DB::table('inventory.products')->insertGetId([
                'company_id' => $companyId,
                'category_id' => $payload['category_id'] ?? null,
                'unit_id' => $normalizedUnitId,
                'line_id' => $payload['line_id'] ?? null,
                'brand_id' => $payload['brand_id'] ?? null,
                'location_id' => $payload['location_id'] ?? null,
                'warranty_id' => $payload['warranty_id'] ?? null,
                'product_nature' => $normalizedNature,
                'sku' => $normalizedSku,
                'barcode' => $normalizedBarcode,
                'sunat_code' => $payload['sunat_code'] ?? null,
                'image_url' => $payload['image_url'] ?? null,
                'seller_commission_percent' => $payload['seller_commission_percent'] ?? 0,
                'name' => $normalizedName,
                'sale_price' => $payload['sale_price'] ?? 0,
                'cost_price' => $payload['cost_price'] ?? 0,
                'is_stockable' => (bool) ($payload['is_stockable'] ?? true),
                'lot_tracking' => (bool) ($payload['lot_tracking'] ?? false),
                'has_expiration' => (bool) ($payload['has_expiration'] ?? false),
                'status' => (int) ($payload['status'] ?? 1),
            ]);

            if ($initialQty > 0 && $warehouseId !== null) {
                DB::table('inventory.inventory_ledger')->insert([
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => (int) $newId,
                    'lot_id' => null,
                    'movement_type' => 'IN',
                    'quantity' => $initialQty,
                    'unit_cost' => $initialCost,
                    'ref_type' => 'PRODUCT_CREATE',
                    'ref_id' => (int) $newId,
                    'notes' => $stockNote ?? 'Stock inicial desde creación de producto',
                    'moved_at' => now(),
                    'created_by' => ($userId !== null && $userId > 0) ? $userId : null,
                ]);
            }

            return (int) $newId;
        });

        $message = $initialQty > 0
            ? 'Product created with initial stock'
            : 'Product created';

        return ['ok' => true, 'status' => 201, 'message' => $message, 'id' => (int) $id];
    }

    public function updateProduct(int $companyId, int $id, array $payload, ?int $userId = null): array
    {
        $productRow = DB::table('inventory.products')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->select('id', 'cost_price')
            ->first();

        if (!$productRow) {
            return ['ok' => false, 'status' => 404, 'message' => 'Product not found'];
        }

        foreach ([
            ['line_id', 'inventory.product_lines'],
            ['brand_id', 'inventory.product_brands'],
            ['location_id', 'inventory.product_locations'],
            ['warranty_id', 'inventory.product_warranties'],
        ] as $masterRule) {
            [$field, $table] = $masterRule;
            if (array_key_exists($field, $payload) && !empty($payload[$field]) && !$this->productMasterExists($table, (int) $payload[$field], $companyId)) {
                return ['ok' => false, 'status' => 422, 'message' => 'Invalid ' . $field];
            }
        }

        $changes = [];
        foreach ([
            'category_id',
            'unit_id',
            'line_id',
            'brand_id',
            'location_id',
            'warranty_id',
            'sunat_code',
            'image_url',
            'seller_commission_percent',
            'sale_price',
            'cost_price',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $changes[$field] = $payload[$field];
            }
        }

        if (array_key_exists('product_nature', $payload)) {
            $changes['product_nature'] = $payload['product_nature'];
        }
        if (array_key_exists('sku', $payload)) {
            $changes['sku'] = $payload['sku'] ? strtoupper(trim((string) $payload['sku'])) : null;
        }
        if (array_key_exists('barcode', $payload)) {
            $changes['barcode'] = $payload['barcode'];
        }
        if (array_key_exists('name', $payload)) {
            $changes['name'] = trim((string) $payload['name']);
        }
        if (array_key_exists('is_stockable', $payload)) {
            $changes['is_stockable'] = (bool) $payload['is_stockable'];
        }
        if (array_key_exists('lot_tracking', $payload)) {
            $changes['lot_tracking'] = (bool) $payload['lot_tracking'];
        }
        if (array_key_exists('has_expiration', $payload)) {
            $changes['has_expiration'] = (bool) $payload['has_expiration'];
        }
        if (array_key_exists('status', $payload)) {
            $changes['status'] = (int) $payload['status'];
        }

        $stockAdjustQty = $this->normalizeNumeric($payload['stock_adjust_qty'] ?? null, 0);
        $hasStockAdjustment = abs($stockAdjustQty) > 0.0000001;
        $stockAdjustCost = round(max(0, $this->normalizeNumeric($payload['stock_adjust_cost'] ?? ($payload['cost_price'] ?? $productRow->cost_price ?? 0), 0)), 4);
        $stockNote = $this->nullIfBlank((string) ($payload['stock_note'] ?? ''));
        $warehouseId = null;

        if ($hasStockAdjustment) {
            if (!empty($payload['warehouse_id'])) {
                $warehouseRow = DB::table('inventory.warehouses')
                    ->where('company_id', $companyId)
                    ->where('status', 1)
                    ->where('id', (int) $payload['warehouse_id'])
                    ->select('id')
                    ->first();

                if (!$warehouseRow) {
                    return ['ok' => false, 'status' => 422, 'message' => 'warehouse_id no existe o está inactivo.'];
                }

                $warehouseId = (int) $warehouseRow->id;
            } else {
                $warehouseCode = strtoupper(trim((string) ($payload['warehouse_code'] ?? '')));
                if ($warehouseCode !== '') {
                    $warehouseCache = [];
                    $warehouseId = $this->resolveWarehouseIdFromCode($companyId, $warehouseCode, $warehouseCache);
                    if ($warehouseId === null) {
                        return ['ok' => false, 'status' => 422, 'message' => 'warehouse_code no existe o está inactivo.'];
                    }
                } else {
                    $defaultWarehouse = $this->resolveDefaultWarehouseForImport($companyId);
                    $warehouseId = $defaultWarehouse['id'] ?? null;
                }
            }

            if (!$warehouseId) {
                return ['ok' => false, 'status' => 422, 'message' => 'No existe almacén activo para aplicar ajuste de stock.'];
            }
        }

        if (empty($changes) && !$hasStockAdjustment) {
            return ['ok' => false, 'status' => 422, 'message' => 'No changes provided'];
        }

        if (
            array_key_exists('sku', $changes)
            || array_key_exists('barcode', $changes)
            || array_key_exists('name', $changes)
            || array_key_exists('unit_id', $changes)
            || array_key_exists('product_nature', $changes)
        ) {
            $current = DB::table('inventory.products')
                ->where('id', $id)
                ->where('company_id', $companyId)
                ->select('sku', 'barcode', 'name', 'unit_id', 'product_nature')
                ->first();

            if (!$current) {
                return ['ok' => false, 'status' => 404, 'message' => 'Product not found'];
            }

            $finalSku = array_key_exists('sku', $changes)
                ? ($changes['sku'] !== null ? trim((string) $changes['sku']) : null)
                : ($current->sku !== null ? trim((string) $current->sku) : null);
            if ($finalSku === '') {
                $finalSku = null;
            }

            $finalBarcode = array_key_exists('barcode', $changes)
                ? ($changes['barcode'] !== null ? trim((string) $changes['barcode']) : null)
                : ($current->barcode !== null ? trim((string) $current->barcode) : null);
            if ($finalBarcode === '') {
                $finalBarcode = null;
            }

            $finalName = array_key_exists('name', $changes)
                ? trim((string) $changes['name'])
                : trim((string) $current->name);

            $finalUnitId = array_key_exists('unit_id', $changes)
                ? ($changes['unit_id'] !== null ? (int) $changes['unit_id'] : null)
                : ($current->unit_id !== null ? (int) $current->unit_id : null);

            $finalNature = array_key_exists('product_nature', $changes)
                ? $this->normalizeProductNature((string) $changes['product_nature'])
                : $this->normalizeProductNature((string) $current->product_nature);

            $duplicate = $this->findExistingActiveProduct(
                $companyId,
                $finalSku,
                $finalBarcode,
                $finalName,
                $finalUnitId,
                $finalNature,
                $id
            );

            if ($duplicate) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'El producto ya existe y no se puede registrar duplicado.',
                    'duplicate_product_id' => (int) $duplicate->id,
                ];
            }
        }

        try {
            DB::transaction(function () use (
                $companyId,
                $id,
                $changes,
                $hasStockAdjustment,
                $stockAdjustQty,
                $stockAdjustCost,
                $stockNote,
                $warehouseId,
                $userId
            ) {
                if (!empty($changes)) {
                    DB::table('inventory.products')
                        ->where('id', $id)
                        ->where('company_id', $companyId)
                        ->update($changes);
                }

                if ($hasStockAdjustment && $warehouseId !== null) {
                    $movementType = $stockAdjustQty > 0 ? 'IN' : 'OUT';
                    $quantity = round(abs($stockAdjustQty), 3);

                    if ($movementType === 'OUT') {
                        $currentStockRow = DB::table('inventory.current_stock')
                            ->where('company_id', $companyId)
                            ->where('warehouse_id', $warehouseId)
                            ->where('product_id', $id)
                            ->select('stock')
                            ->first();

                        $currentStock = $currentStockRow ? (float) ($currentStockRow->stock ?? 0) : 0.0;
                        if (($currentStock + 0.0000001) < $quantity) {
                            throw new \RuntimeException('Stock insuficiente para salida. Disponible: ' . number_format($currentStock, 3, '.', '') . ', solicitado: ' . number_format($quantity, 3, '.', ''));
                        }
                    }

                    DB::table('inventory.inventory_ledger')->insert([
                        'company_id' => $companyId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $id,
                        'lot_id' => null,
                        'movement_type' => $movementType,
                        'quantity' => $quantity,
                        'unit_cost' => $stockAdjustCost,
                        'ref_type' => 'PRODUCT_EDIT',
                        'ref_id' => $id,
                        'notes' => $stockNote ?? 'Ajuste de stock desde edición de producto',
                        'moved_at' => now(),
                        'created_by' => ($userId !== null && $userId > 0) ? $userId : null,
                    ]);
                }
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'status' => 422, 'message' => $e->getMessage()];
        }

        $message = $hasStockAdjustment
            ? 'Product updated with stock adjustment'
            : 'Product updated';

        return ['ok' => true, 'status' => 200, 'message' => $message];
    }

    public function listProductMasters(int $companyId): array
    {
        $lines = DB::table('inventory.product_lines')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $brands = DB::table('inventory.product_brands')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $locations = DB::table('inventory.product_locations')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $warranties = DB::table('inventory.product_warranties')
            ->select('id', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return [
            'lines' => $lines,
            'brands' => $brands,
            'locations' => $locations,
            'warranties' => $warranties,
        ];
    }

    public function createProductMaster(int $companyId, int $userId, string $kind, string $name): array
    {
        $table = $this->resolveMasterTable($kind);
        if ($table === null) {
            return ['ok' => false, 'status' => 422, 'message' => 'Invalid master kind'];
        }

        $normalizedName = trim($name);
        if ($normalizedName === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Name cannot be empty'];
        }

        $existing = DB::table($table)
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(name) = LOWER(?)', [$normalizedName])
            ->first();

        if ($existing) {
            if ((int) $existing->status !== 1) {
                DB::table($table)
                    ->where('id', (int) $existing->id)
                    ->update([
                        'status' => 1,
                        'updated_at' => now(),
                    ]);
            }

            return [
                'ok' => true,
                'status' => 200,
                'data' => ['id' => (int) $existing->id, 'name' => $existing->name, 'status' => 1],
            ];
        }

        $id = DB::table($table)->insertGetId([
            'company_id' => $companyId,
            'name' => $normalizedName,
            'status' => 1,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'ok' => true,
            'status' => 201,
            'data' => ['id' => (int) $id, 'name' => $normalizedName, 'status' => 1],
        ];
    }

    public function updateProductMaster(int $companyId, int $id, string $kind, array $payload): array
    {
        $table = $this->resolveMasterTable($kind);
        if ($table === null) {
            return ['ok' => false, 'status' => 422, 'message' => 'Invalid master kind'];
        }

        $master = DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$master) {
            return ['ok' => false, 'status' => 404, 'message' => 'Master not found'];
        }

        $changes = [];

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return ['ok' => false, 'status' => 422, 'message' => 'Name cannot be empty'];
            }

            $duplicate = DB::table($table)
                ->where('company_id', $companyId)
                ->where('id', '<>', $id)
                ->whereRaw('LOWER(name) = LOWER(?)', [$name])
                ->exists();

            if ($duplicate) {
                return ['ok' => false, 'status' => 422, 'message' => 'Name already exists'];
            }

            $changes['name'] = $name;
        }

        if (array_key_exists('status', $payload)) {
            $changes['status'] = (int) $payload['status'];
        }

        if (empty($changes)) {
            return ['ok' => false, 'status' => 422, 'message' => 'No changes provided'];
        }

        $changes['updated_at'] = now();

        DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($changes);

        $updated = DB::table($table)
            ->select('id', 'name', 'status')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'id' => (int) $updated->id,
                'name' => $updated->name,
                'status' => (int) $updated->status,
            ],
        ];
    }

    public function listImportBatches(int $companyId, int $limit): array
    {
        $limit = max(1, min($limit, 200));

        $rows = DB::table('inventory.product_import_batches as b')
            ->leftJoin('auth.users as u', 'u.id', '=', 'b.imported_by')
            ->where('b.company_id', $companyId)
            ->orderByDesc('b.id')
            ->limit($limit)
            ->select([
                'b.id',
                'b.company_id',
                'b.imported_by',
                'b.filename',
                'b.total_rows',
                'b.created_count',
                'b.updated_count',
                'b.skipped_count',
                'b.error_count',
                'b.status',
                'b.started_at',
                'b.finished_at',
                'b.created_at',
                DB::raw("TRIM(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')) as imported_by_name"),
                'u.username as imported_by_username',
            ])
            ->get();

        return [
            'ok' => true,
            'status' => 200,
            'data' => $rows,
        ];
    }

    public function getImportBatchDetail(int $companyId, int $batchId, int $itemsLimit): array
    {
        $itemsLimit = max(1, min($itemsLimit, 2000));

        $batch = DB::table('inventory.product_import_batches as b')
            ->leftJoin('auth.users as u', 'u.id', '=', 'b.imported_by')
            ->where('b.id', $batchId)
            ->where('b.company_id', $companyId)
            ->select([
                'b.id',
                'b.company_id',
                'b.imported_by',
                'b.filename',
                'b.total_rows',
                'b.created_count',
                'b.updated_count',
                'b.skipped_count',
                'b.error_count',
                'b.errors_json',
                'b.status',
                'b.started_at',
                'b.finished_at',
                'b.created_at',
                DB::raw("TRIM(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')) as imported_by_name"),
                'u.username as imported_by_username',
            ])
            ->first();

        if (!$batch) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Lote de importación no encontrado',
            ];
        }

        $items = DB::table('inventory.product_import_batch_items')
            ->where('batch_id', $batchId)
            ->orderBy('id')
            ->limit($itemsLimit)
            ->get([
                'id',
                'batch_id',
                'row_number',
                'action_status',
                'product_id',
                'sku',
                'barcode',
                'name',
                'message',
                'created_at',
            ]);

        $errors = [];
        if ($batch->errors_json !== null) {
            $decoded = is_string($batch->errors_json)
                ? json_decode($batch->errors_json, true)
                : $batch->errors_json;
            if (is_array($decoded)) {
                $errors = $decoded;
            }
        }

        unset($batch->errors_json);

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'batch' => $batch,
                'items' => $items,
                'errors' => $errors,
            ],
        ];
    }

    private function productMasterExists(string $table, int $id, int $companyId): bool
    {
        return DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    private function normalizeProductNature(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if (in_array($normalized, ['SUPPLY', 'INSUMO', 'INSUMOS'], true)) {
            return 'SUPPLY';
        }

        return 'PRODUCT';
    }

    private function findExistingActiveProduct(
        int $companyId,
        ?string $sku,
        ?string $barcode,
        string $name,
        ?int $unitId,
        string $nature,
        ?int $excludeProductId = null
    ): ?\App\Application\DTOs\Inventory\InventoryProductReferenceDTO {
        if ($sku !== null) {
            $query = DB::table('inventory.products')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereRaw("UPPER(COALESCE(sku, '')) = ?", [strtoupper(trim($sku))]);

            if ($excludeProductId !== null) {
                $query->where('id', '<>', $excludeProductId);
            }

            $row = $query->select('id')->first();
            if ($row) {
                return \App\Application\DTOs\Inventory\InventoryProductReferenceDTO::fromRow($row);
            }
        }

        if ($barcode !== null) {
            $query = DB::table('inventory.products')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('barcode', trim($barcode));

            if ($excludeProductId !== null) {
                $query->where('id', '<>', $excludeProductId);
            }

            $row = $query->select('id')->first();
            if ($row) {
                return \App\Application\DTOs\Inventory\InventoryProductReferenceDTO::fromRow($row);
            }
        }

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

        return $row ? \App\Application\DTOs\Inventory\InventoryProductReferenceDTO::fromRow($row) : null;
    }

    private function resolveMasterTable(string $kind): ?string
    {
        return [
            'line' => 'inventory.product_lines',
            'brand' => 'inventory.product_brands',
            'location' => 'inventory.product_locations',
            'warranty' => 'inventory.product_warranties',
        ][strtolower(trim($kind))] ?? null;
    }

    private function resolveDefaultUnitId(): ?int
    {
        $row = DB::table('core.units')
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereRaw("UPPER(COALESCE(code, '')) = ?", ['NIU'])
                    ->orWhereRaw("UPPER(COALESCE(sunat_uom_code, '')) = ?", ['NIU'])
                    ->orWhereRaw("UPPER(COALESCE(name, '')) = ?", ['UNIDAD (BIENES)']);
            })
            ->select('id')
            ->orderBy('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    private function buildUnitLookupMap(): array
    {
        $rows = DB::table('core.units')
            ->where('status', 1)
            ->select('id', 'code', 'sunat_uom_code', 'name')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            foreach ([(string) $row->code, (string) $row->sunat_uom_code, (string) $row->name] as $key) {
                $normalized = strtoupper(trim($key));
                if ($normalized !== '') {
                    $map[$normalized] = $id;
                }
            }
        }

        return $map;
    }

    private function resolveUnitIdFromCode(string $unitCode, array $unitMap, int $defaultUnitId): int
    {
        $normalized = strtoupper(trim($unitCode));
        if ($normalized === '') {
            return $defaultUnitId;
        }

        return $unitMap[$normalized] ?? $defaultUnitId;
    }

    private function buildWarehouseLookupMap(int $companyId, array $warehouseCodes): array
    {
        $codes = [];
        foreach ($warehouseCodes as $warehouseCode) {
            $normalized = strtoupper(trim((string) $warehouseCode));
            if ($normalized !== '') {
                $codes[$normalized] = true;
            }
        }

        if (empty($codes)) {
            return [];
        }

        $rows = DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->where(function ($query) use ($codes) {
                $query
                    ->whereIn(DB::raw("UPPER(COALESCE(code, ''))"), array_keys($codes))
                    ->orWhereIn(DB::raw("UPPER(COALESCE(name, ''))"), array_keys($codes));
            })
            ->select('id', 'code', 'name')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $codeKey = strtoupper(trim((string) ($row->code ?? '')));
            $nameKey = strtoupper(trim((string) ($row->name ?? '')));

            if ($codeKey !== '' && isset($codes[$codeKey]) && !isset($map[$codeKey])) {
                $map[$codeKey] = $id;
            }
            if ($nameKey !== '' && isset($codes[$nameKey]) && !isset($map[$nameKey])) {
                $map[$nameKey] = $id;
            }
        }

        foreach (array_keys($codes) as $code) {
            if (!array_key_exists($code, $map)) {
                $map[$code] = null;
            }
        }

        return $map;
    }

    private function prefetchExistingProductsForImport(
        int $companyId,
        array $candidateIds,
        array $candidateSkus,
        array $candidateBarcodes,
        array $candidateNames
    ): array {
        if (empty($candidateIds) && empty($candidateSkus) && empty($candidateBarcodes) && empty($candidateNames)) {
            return [
                'by_id' => [],
                'by_sku' => [],
                'by_barcode' => [],
                'by_composite' => [],
            ];
        }

        $candidateSkuSet = array_fill_keys($candidateSkus, true);
        $candidateBarcodeSet = array_fill_keys($candidateBarcodes, true);
        $candidateNameSet = array_fill_keys($candidateNames, true);

        $rows = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->where(function ($query) use ($candidateIds, $candidateSkus, $candidateBarcodes, $candidateNames) {
                $hasCondition = false;
                if (!empty($candidateIds)) {
                    $query->whereIn('id', $candidateIds);
                    $hasCondition = true;
                }
                if (!empty($candidateSkus)) {
                    if ($hasCondition) {
                        $query->orWhereIn(DB::raw("UPPER(COALESCE(sku, ''))"), $candidateSkus);
                    } else {
                        $query->whereIn(DB::raw("UPPER(COALESCE(sku, ''))"), $candidateSkus);
                        $hasCondition = true;
                    }
                }
                if (!empty($candidateBarcodes)) {
                    if ($hasCondition) {
                        $query->orWhereIn('barcode', $candidateBarcodes);
                    } else {
                        $query->whereIn('barcode', $candidateBarcodes);
                        $hasCondition = true;
                    }
                }
                if (!empty($candidateNames)) {
                    if ($hasCondition) {
                        $query->orWhereIn(DB::raw("UPPER(TRIM(COALESCE(name, '')))"), $candidateNames);
                    } else {
                        $query->whereIn(DB::raw("UPPER(TRIM(COALESCE(name, '')))"), $candidateNames);
                    }
                }
            })
            ->select('id', 'sku', 'barcode', 'name', 'unit_id', 'product_nature', 'deleted_at')
            ->get();

        $byId = [];
        $bySku = [];
        $byBarcode = [];
        $byComposite = [];
        $preferredById = [];
        $preferredBySku = [];
        $preferredByBarcode = [];
        $preferredByComposite = [];

        foreach ($rows as $row) {
            $productId = (int) $row->id;

            $currentById = $preferredById[$productId] ?? null;
            if ($this->shouldPreferProductCandidate($currentById, $row)) {
                $preferredById[$productId] = $row;
                $byId[$productId] = $productId;
            }

            $skuKey = strtoupper(trim((string) ($row->sku ?? '')));
            if ($skuKey !== '' && isset($candidateSkuSet[$skuKey])) {
                $currentBySku = $preferredBySku[$skuKey] ?? null;
                if ($this->shouldPreferProductCandidate($currentBySku, $row)) {
                    $preferredBySku[$skuKey] = $row;
                    $bySku[$skuKey] = $productId;
                }
            }

            $barcodeKey = trim((string) ($row->barcode ?? ''));
            if ($barcodeKey !== '' && isset($candidateBarcodeSet[$barcodeKey])) {
                $currentByBarcode = $preferredByBarcode[$barcodeKey] ?? null;
                if ($this->shouldPreferProductCandidate($currentByBarcode, $row)) {
                    $preferredByBarcode[$barcodeKey] = $row;
                    $byBarcode[$barcodeKey] = $productId;
                }
            }

            $nameKey = strtoupper(trim((string) ($row->name ?? '')));
            if ($nameKey !== '' && isset($candidateNameSet[$nameKey])) {
                $nature = $this->normalizeProductNature((string) ($row->product_nature ?? 'PRODUCT'));
                $compositeKey = $this->buildProductCompositeLookupKey(
                    (string) $row->name,
                    $row->unit_id !== null ? (int) $row->unit_id : null,
                    $nature
                );
                $currentByComposite = $preferredByComposite[$compositeKey] ?? null;
                if ($this->shouldPreferProductCandidate($currentByComposite, $row)) {
                    $preferredByComposite[$compositeKey] = $row;
                    $byComposite[$compositeKey] = $productId;
                }
            }
        }

        return [
            'by_id' => $byId,
            'by_sku' => $bySku,
            'by_barcode' => $byBarcode,
            'by_composite' => $byComposite,
        ];
    }

    private function shouldPreferProductCandidate(?object $current, object $candidate): bool
    {
        if ($current === null) {
            return true;
        }

        $currentIsDeleted = $current->deleted_at !== null;
        $candidateIsDeleted = $candidate->deleted_at !== null;

        if ($currentIsDeleted !== $candidateIsDeleted) {
            return !$candidateIsDeleted;
        }

        return (int) $candidate->id < (int) $current->id;
    }

    private function buildProductCompositeLookupKey(string $name, ?int $unitId, string $nature): string
    {
        return strtoupper(trim($name))
            . '|UNIT:' . (string) ($unitId ?? 0)
            . '|NATURE:' . $this->normalizeProductNature($nature);
    }

    private function resolveWarehouseIdFromCode(int $companyId, string $warehouseCode, array &$cache): ?int
    {
        $normalized = strtoupper(trim($warehouseCode));
        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, $cache)) {
            return $cache[$normalized];
        }

        $row = DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw("UPPER(COALESCE(code, '')) = ?", [$normalized])
                    ->orWhereRaw("UPPER(COALESCE(name, '')) = ?", [$normalized]);
            })
            ->where('status', 1)
            ->select('id')
            ->first();

        $cache[$normalized] = $row ? (int) $row->id : null;
        return $cache[$normalized];
    }

    private function resolveDefaultWarehouseForImport(int $companyId): ?array
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

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => strtoupper(trim((string) ($row->code ?? ''))),
        ];
    }

    private function normalizeNumeric($value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized)) {
            return $default;
        }

        return (float) $normalized;
    }

    private function normalizeBoolean($value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtoupper(trim((string) $value));
        if (in_array($normalized, ['1', 'TRUE', 'SI', 'S', 'YES', 'Y', 'ACTIVO'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'FALSE', 'NO', 'N', 'INACTIVO'], true)) {
            return false;
        }

        return $default;
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function seedStockProjection(int $companyId, array $warehouseIds, array $productIds): void
    {
        if (empty($warehouseIds) || empty($productIds)) {
            return;
        }

        $rows = DB::table('inventory.current_stock')
            ->where('company_id', $companyId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->select('warehouse_id', 'product_id', 'stock')
            ->get();

        foreach ($rows as $row) {
            $projectionKey = $companyId . ':' . (int) $row->warehouse_id . ':' . (int) $row->product_id;
            $this->stockProjection[$projectionKey] = (float) ($row->stock ?? 0);
        }
    }

    private function getProjectedCurrentStock(int $companyId, int $warehouseId, int $productId): float
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;

        if (!array_key_exists($projectionKey, $this->stockProjection)) {
            $row = DB::table('inventory.current_stock')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->select('stock')
                ->first();

            $this->stockProjection[$projectionKey] = $row ? (float) ($row->stock ?? 0) : 0.0;
        }

        return (float) $this->stockProjection[$projectionKey];
    }

    private function applyCurrentStockDelta(int $companyId, int $warehouseId, int $productId, float $delta, bool $allowNegativeStock): void
    {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;
        $current = $this->getProjectedCurrentStock($companyId, $warehouseId, $productId);
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for product #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }
}
