<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Domain\Inventory\Repositories\InventoryProductCommercialRepositoryInterface;
use Illuminate\Support\Facades\DB;

class InventoryProductCommercialRepository implements InventoryProductCommercialRepositoryInterface
{
    private const FEATURE_MULTI_UOM = 'PRODUCT_MULTI_UOM';
    private const FEATURE_UOM_CONVERSIONS = 'PRODUCT_UOM_CONVERSIONS';
    private const FEATURE_WHOLESALE_PRICING = 'PRODUCT_WHOLESALE_PRICING';

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

        $this->ensureProductSaleUnitsTable();
        $this->ensureProductPriceTierValuesTable();
        $this->ensureProductTierPricesTable();

        $features = $this->commerceFeatures($companyId);

        $enabledUnits = DB::table('core.units as u')
            ->join('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId)
                    ->where('cu.is_enabled', '=', true);
            })
            ->select('u.id', 'u.code', 'u.name', 'u.sunat_uom_code')
            ->orderBy('u.name')
            ->get();

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

        $priceTiers = DB::table('sales.price_tiers')
            ->select('id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('min_qty')
            ->get();

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

        $this->ensureProductSaleUnitsTable();
        $this->ensureProductPriceTierValuesTable();

        DB::transaction(function () use ($payload, $companyId, $productId, $authUser) {
            if (array_key_exists('base_unit_id', $payload)) {
                DB::table('inventory.products')
                    ->where('id', $productId)
                    ->where('company_id', $companyId)
                    ->update([
                        'unit_id' => $payload['base_unit_id'],
                    ]);
            }

            if (array_key_exists('units', $payload)) {
                DB::table('inventory.product_sale_units')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->delete();

                foreach ($payload['units'] as $row) {
                    DB::table('inventory.product_sale_units')->insert([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'unit_id' => (int) $row['unit_id'],
                        'is_base' => (bool) ($row['is_base'] ?? false),
                        'status' => (int) ($row['status'] ?? 1),
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('conversions', $payload)) {
                DB::table('inventory.product_uom_conversions')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->delete();

                foreach ($payload['conversions'] as $row) {
                    DB::table('inventory.product_uom_conversions')->insert([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'from_unit_id' => (int) $row['from_unit_id'],
                        'to_unit_id' => (int) $row['to_unit_id'],
                        'conversion_factor' => $row['conversion_factor'],
                        'status' => (int) ($row['status'] ?? 1),
                        'created_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('wholesale_prices', $payload)) {
                DB::table('sales.product_price_tier_values')
                    ->where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->delete();

                foreach ($payload['wholesale_prices'] as $row) {
                    DB::table('sales.product_price_tier_values')->insert([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'price_tier_id' => (int) $row['price_tier_id'],
                        'unit_id' => isset($row['unit_id']) ? (int) $row['unit_id'] : null,
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

    private function ensureProductSaleUnitsTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS inventory.product_sale_units (
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                unit_id BIGINT NOT NULL,
                is_base BOOLEAN NOT NULL DEFAULT FALSE,
                status SMALLINT NOT NULL DEFAULT 1,
                updated_by BIGINT NULL,
                updated_at TIMESTAMPTZ NULL,
                PRIMARY KEY (company_id, product_id, unit_id)
            )'
        );
    }

    private function ensureProductPriceTierValuesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.product_price_tier_values (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                price_tier_id BIGINT NOT NULL,
                unit_id BIGINT NULL,
                unit_price NUMERIC(18,6) NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                updated_by BIGINT NULL,
                updated_at TIMESTAMPTZ NULL,
                UNIQUE(company_id, product_id, price_tier_id, unit_id)
            )'
        );
    }

    private function ensureProductTierPricesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.product_tier_prices (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                tier_id BIGINT NOT NULL,
                currency_id BIGINT NOT NULL,
                unit_price NUMERIC(14,4) NOT NULL,
                valid_from TIMESTAMPTZ NULL,
                valid_to TIMESTAMPTZ NULL,
                status SMALLINT NOT NULL DEFAULT 1
            )'
        );
    }
}
