<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Domain\Inventory\Repositories\InventoryProductCommercialRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryProductCommercialRepository implements InventoryProductCommercialRepositoryInterface
{
    private const FEATURE_MULTI_UOM = 'PRODUCT_MULTI_UOM';
    private const FEATURE_UOM_CONVERSIONS = 'PRODUCT_UOM_CONVERSIONS';
    private const FEATURE_WHOLESALE_PRICING = 'PRODUCT_WHOLESALE_PRICING';
    private const COMPANY_CONFIG_CACHE_MINUTES = 5;

    public function getProductCommercialConfig(int $companyId, int $productId): ?array
    {
        $product = DB::table('inventory.products')
            ->select('id', 'company_id', 'name', 'unit_id', 'sale_price')
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$product) {
            return null;
        }

        $features = $this->commerceFeatures($companyId);

        $enabledUnits = Cache::remember(
            $this->companyCacheKey($companyId, 'enabled_units'),
            now()->addMinutes(self::COMPANY_CONFIG_CACHE_MINUTES),
            function () use ($companyId) {
                $hasCompanyUnitsTable = DB::getSchemaBuilder()->hasTable('appcfg.company_units');

                if ($hasCompanyUnitsTable) {
                    $rows = DB::table('core.units as u')
                        ->join('appcfg.company_units as cu', function ($join) use ($companyId) {
                            $join->on('cu.unit_id', '=', 'u.id')
                                ->where('cu.company_id', '=', $companyId)
                                ->where('cu.is_enabled', '=', true);
                        })
                        ->select('u.id', 'u.code', 'u.name', 'u.sunat_uom_code')
                        ->orderBy('u.name')
                        ->get();

                    if ($rows->isNotEmpty()) {
                        return $rows;
                    }
                }

                // Fallback for legacy/misaligned environments without appcfg.company_units seed.
                return DB::table('core.units')
                    ->select('id', 'code', 'name', 'sunat_uom_code')
                    ->orderBy('name')
                    ->get();
            }
        );

        $productUnits = DB::table('inventory.product_sale_units as pu')
            ->join('core.units as u', 'u.id', '=', 'pu.unit_id')
            ->select([
                'pu.unit_id',
                'pu.is_base',
                'pu.status',
                'u.code',
                'u.name',
                'u.sunat_uom_code',
            ])
            ->where('pu.company_id', $companyId)
            ->where('pu.product_id', $productId)
            ->orderByDesc('pu.is_base')
            ->orderBy('u.name')
            ->get();

        if ($productUnits->isEmpty() && $product->unit_id) {
            $baseUnit = DB::table('core.units')
                ->select('id as unit_id', 'code', 'name', 'sunat_uom_code')
                ->where('id', (int) $product->unit_id)
                ->first();

            if ($baseUnit) {
                $productUnits = collect([
                    [
                        'unit_id' => (int) $baseUnit->unit_id,
                        'is_base' => true,
                        'status' => 1,
                        'code' => $baseUnit->code,
                        'name' => $baseUnit->name,
                        'sunat_uom_code' => $baseUnit->sunat_uom_code,
                    ],
                ]);
            }
        }

        $conversions = collect();
        if ($features[self::FEATURE_UOM_CONVERSIONS]) {
            $conversions = DB::table('inventory.product_uom_conversions as c')
                ->join('core.units as fu', 'fu.id', '=', 'c.from_unit_id')
                ->join('core.units as tu', 'tu.id', '=', 'c.to_unit_id')
                ->select([
                    'c.id',
                    'c.from_unit_id',
                    'fu.code as from_unit_code',
                    'fu.name as from_unit_name',
                    'c.to_unit_id',
                    'tu.code as to_unit_code',
                    'tu.name as to_unit_name',
                    'c.conversion_factor',
                    'c.status',
                ])
                ->where('c.company_id', $companyId)
                ->where('c.product_id', $productId)
                ->orderBy('fu.name')
                ->get();
        }

        $wholesalePrices = collect();
        $priceTiers = collect();
        $profileTierPrices = collect();
        if ($features[self::FEATURE_WHOLESALE_PRICING]) {
            $wholesalePrices = DB::table('sales.product_price_tier_values as ptv')
                ->join('sales.price_tiers as pt', 'pt.id', '=', 'ptv.price_tier_id')
                ->leftJoin('core.units as u', 'u.id', '=', 'ptv.unit_id')
                ->select([
                    'ptv.id',
                    'ptv.price_tier_id',
                    'pt.code as tier_code',
                    'pt.name as tier_name',
                    'pt.min_qty',
                    'pt.max_qty',
                    'ptv.unit_id',
                    'u.code as unit_code',
                    'u.name as unit_name',
                    'ptv.unit_price',
                    'ptv.status',
                ])
                ->where('ptv.company_id', $companyId)
                ->where('ptv.product_id', $productId)
                ->where('pt.status', 1)
                ->orderBy('pt.priority')
                ->orderBy('pt.min_qty')
                ->get();

            $priceTiers = Cache::remember(
                $this->companyCacheKey($companyId, 'price_tiers'),
                now()->addMinutes(self::COMPANY_CONFIG_CACHE_MINUTES),
                function () use ($companyId) {
                    return DB::table('sales.price_tiers')
                        ->select('id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
                        ->where('company_id', $companyId)
                        ->where('status', 1)
                        ->orderBy('priority')
                        ->orderBy('min_qty')
                        ->get();
                }
            );

            $profileTierPrices = DB::table('sales.product_tier_prices as ptp')
                ->join('sales.price_tiers as pt', function ($join) use ($companyId) {
                    $join->on('pt.id', '=', 'ptp.tier_id')
                        ->where('pt.company_id', '=', $companyId);
                })
                ->select([
                    'ptp.id',
                    'ptp.tier_id',
                    'pt.code as tier_code',
                    'pt.name as tier_name',
                    'ptp.currency_id',
                    'ptp.unit_price',
                    'ptp.valid_from',
                    'ptp.valid_to',
                    'ptp.status',
                ])
                ->where('ptp.company_id', $companyId)
                ->where('ptp.product_id', $productId)
                ->where('ptp.status', 1)
                ->orderByDesc('ptp.valid_from')
                ->orderBy('pt.priority')
                ->get();
        }

        return [
            'product' => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'unit_id' => $product->unit_id ? (int) $product->unit_id : null,
                'sale_price' => (float) $product->sale_price,
            ],
            'features' => $features,
            'enabled_units' => $enabledUnits,
            'product_units' => $productUnits,
            'conversions' => $conversions,
            'price_tiers' => $priceTiers,
            'wholesale_prices' => $wholesalePrices,
            'profile_tier_prices' => $profileTierPrices,
        ];
    }

    public function updateProductCommercialConfig(object $authUser, int $companyId, int $productId, array $payload): void
    {
        $exists = DB::table('inventory.products')
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$exists) {
            throw new \RuntimeException('Product not found');
        }

        $this->assertCommercialTablesReady();

        $features = $this->commerceFeatures($companyId);
        $enabledUnitIds = DB::table('core.units')->pluck('id')->map(fn($id) => (int) $id)->all();
        $enabledUnitMap = array_fill_keys($enabledUnitIds, true);

        DB::transaction(function () use ($payload, $companyId, $productId, $authUser, $features, $enabledUnitMap) {
            $productRow = DB::table('inventory.products')
                ->where('id', $productId)
                ->where('company_id', $companyId)
                ->select('unit_id')
                ->first();

            $baseUnitId = array_key_exists('base_unit_id', $payload)
                ? ($payload['base_unit_id'] !== null ? (int) $payload['base_unit_id'] : null)
                : ($productRow && $productRow->unit_id !== null ? (int) $productRow->unit_id : null);

            $unitsPayload = [];
            if (array_key_exists('units', $payload)) {
                if (!$features[self::FEATURE_MULTI_UOM]) {
                    throw new \RuntimeException('La empresa no tiene habilitada la funcionalidad de multiples unidades por producto.');
                }

                foreach (($payload['units'] ?? []) as $row) {
                    $unitId = (int) ($row['unit_id'] ?? 0);
                    if ($unitId <= 0) {
                        throw new \RuntimeException('Unidad de venta invalida.');
                    }
                    if (!isset($enabledUnitMap[$unitId])) {
                        throw new \RuntimeException('La unidad seleccionada no existe en catalogo.');
                    }

                    if (isset($unitsPayload[$unitId])) {
                        throw new \RuntimeException('No se permiten unidades repetidas para el mismo producto.');
                    }

                    $unitsPayload[$unitId] = [
                        'unit_id' => $unitId,
                        'is_base' => (bool) ($row['is_base'] ?? false),
                        'status' => (int) ($row['status'] ?? 1) === 1 ? 1 : 0,
                    ];
                }

                if (empty($unitsPayload)) {
                    throw new \RuntimeException('Debes registrar al menos una unidad de venta.');
                }

                $declaredBaseUnitId = null;
                foreach ($unitsPayload as $unitId => $row) {
                    if ($row['is_base']) {
                        $declaredBaseUnitId = $unitId;
                        break;
                    }
                }

                if ($baseUnitId === null) {
                    $baseUnitId = $declaredBaseUnitId ?? (int) array_key_first($unitsPayload);
                }

                if (!isset($unitsPayload[$baseUnitId])) {
                    throw new \RuntimeException('La unidad base debe pertenecer a la lista de unidades del producto.');
                }

                foreach ($unitsPayload as $unitId => $row) {
                    $unitsPayload[$unitId]['is_base'] = $unitId === $baseUnitId;
                }

                DB::table('inventory.product_sale_units')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->delete();

                foreach (array_values($unitsPayload) as $row) {
                    DB::table('inventory.product_sale_units')->insert([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'unit_id' => $row['unit_id'],
                        'is_base' => $row['is_base'],
                        'status' => $row['status'],
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('base_unit_id', $payload) || array_key_exists('units', $payload)) {
                if ($baseUnitId !== null && !isset($enabledUnitMap[$baseUnitId])) {
                    throw new \RuntimeException('La unidad base seleccionada no existe en catalogo.');
                }

                DB::table('inventory.products')
                    ->where('id', $productId)
                    ->where('company_id', $companyId)
                    ->update([
                        'unit_id' => $baseUnitId,
                    ]);
            }

            $allowedUnitIds = [];
            if (!empty($unitsPayload)) {
                $allowedUnitIds = array_keys($unitsPayload);
            } else {
                $existingUnits = DB::table('inventory.product_sale_units')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->pluck('unit_id')
                    ->map(fn($id) => (int) $id)
                    ->all();

                $allowedUnitIds = $existingUnits;
                if ($baseUnitId !== null && !in_array($baseUnitId, $allowedUnitIds, true)) {
                    $allowedUnitIds[] = $baseUnitId;
                }
            }

            $allowedUnitMap = array_fill_keys($allowedUnitIds, true);

            if (array_key_exists('conversions', $payload)) {
                if (!$features[self::FEATURE_UOM_CONVERSIONS]) {
                    throw new \RuntimeException('La empresa no tiene habilitadas las conversiones por unidad.');
                }

                DB::table('inventory.product_uom_conversions')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->delete();

                $pairs = [];
                foreach (($payload['conversions'] ?? []) as $row) {
                    $fromUnitId = (int) ($row['from_unit_id'] ?? 0);
                    $toUnitId = (int) ($row['to_unit_id'] ?? 0);
                    $factor = (float) ($row['conversion_factor'] ?? 0);

                    if ($fromUnitId <= 0 || $toUnitId <= 0 || $fromUnitId === $toUnitId) {
                        throw new \RuntimeException('Cada conversion debe tener unidades origen/destino validas y distintas.');
                    }
                    if ($factor <= 0) {
                        throw new \RuntimeException('El factor de conversion debe ser mayor a cero.');
                    }
                    if (!isset($allowedUnitMap[$fromUnitId]) || !isset($allowedUnitMap[$toUnitId])) {
                        throw new \RuntimeException('Las conversiones solo pueden usar unidades configuradas en el producto.');
                    }

                    $pairKey = $fromUnitId . ':' . $toUnitId;
                    if (isset($pairs[$pairKey])) {
                        throw new \RuntimeException('No se permiten conversiones duplicadas para el mismo par de unidades.');
                    }
                    $pairs[$pairKey] = true;

                    DB::table('inventory.product_uom_conversions')->insert([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'from_unit_id' => $fromUnitId,
                        'to_unit_id' => $toUnitId,
                        'conversion_factor' => $factor,
                        'status' => (int) ($row['status'] ?? 1),
                        'created_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('wholesale_prices', $payload)) {
                if (!$features[self::FEATURE_WHOLESALE_PRICING]) {
                    throw new \RuntimeException('La empresa no tiene habilitados los precios mayoristas por escala.');
                }

                DB::table('sales.product_price_tier_values')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->delete();

                foreach (($payload['wholesale_prices'] ?? []) as $row) {
                    $unitId = isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
                    if ($unitId !== null && !isset($allowedUnitMap[$unitId])) {
                        throw new \RuntimeException('Los precios mayoristas por unidad deben usar unidades configuradas en el producto.');
                    }

                    DB::table('sales.product_price_tier_values')->insert([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'price_tier_id' => (int) $row['price_tier_id'],
                        'unit_id' => $unitId,
                        'unit_price' => $row['unit_price'],
                        'status' => (int) ($row['status'] ?? 1),
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function commerceFeatures(int $companyId): array
    {
        return Cache::remember(
            $this->companyCacheKey($companyId, 'features'),
            now()->addMinutes(self::COMPANY_CONFIG_CACHE_MINUTES),
            function () use ($companyId) {
                $rows = DB::table('appcfg.company_feature_toggles')
                    ->where('company_id', $companyId)
                    ->whereIn('feature_code', [
                        self::FEATURE_MULTI_UOM,
                        self::FEATURE_UOM_CONVERSIONS,
                        self::FEATURE_WHOLESALE_PRICING,
                    ])
                    ->pluck('is_enabled', 'feature_code');

                return [
                    self::FEATURE_MULTI_UOM => (bool) ($rows[self::FEATURE_MULTI_UOM] ?? false),
                    self::FEATURE_UOM_CONVERSIONS => (bool) ($rows[self::FEATURE_UOM_CONVERSIONS] ?? false),
                    self::FEATURE_WHOLESALE_PRICING => (bool) ($rows[self::FEATURE_WHOLESALE_PRICING] ?? false),
                ];
            }
        );
    }

    private function companyCacheKey(int $companyId, string $suffix): string
    {
        return sprintf('inventory:product-commercial:%d:%s', $companyId, $suffix);
    }

    private function assertCommercialTablesReady(): void
    {
        $requiredTables = [
            'inventory.product_sale_units',
            'inventory.product_uom_conversions',
            'sales.product_price_tier_values',
        ];

        foreach ($requiredTables as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                throw new \RuntimeException('Falta la tabla ' . $table . '. Ejecuta migraciones antes de configurar multiples unidades.');
            }
        }
    }
}
