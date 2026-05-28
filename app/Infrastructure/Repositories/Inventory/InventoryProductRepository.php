<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Domain\Inventory\Repositories\InventoryProductRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryProductRepository implements InventoryProductRepositoryInterface
{
    public function getProducts(int $companyId, string $search, $status, int $limit, bool $autocomplete): array
    {
        $limit = max(1, min($limit, 5000));
        $cacheKey = sprintf(
            'inventory_products:%d:%s:%s:%d:%d',
            $companyId,
            md5(mb_strtolower(trim($search))),
            $status === null || $status === '' ? 'all' : (string) (int) $status,
            $limit,
            $autocomplete ? 1 : 0
        );
        $ttlSeconds = $autocomplete ? 15 : 30;

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($companyId, $search, $status, $limit, $autocomplete) {
            $normalizedSearch = mb_strtolower(trim($search));

            $hasRestaurantRecipesTable = DB::getSchemaBuilder()->hasTable('restaurant.product_recipes');
            $recipeFlagSelect = $hasRestaurantRecipesTable
                ? DB::raw('CASE WHEN rr.menu_product_id IS NULL THEN false ELSE true END as has_recipe')
                : DB::raw('false as has_recipe');

            $recipeHeadersSubquery = null;
            if ($hasRestaurantRecipesTable) {
                $recipeHeadersSubquery = DB::table('restaurant.product_recipes')
                    ->select('menu_product_id');

                if (DB::getSchemaBuilder()->hasColumn('restaurant.product_recipes', 'deleted_at')) {
                    $recipeHeadersSubquery->whereNull('deleted_at');
                }

                $recipeHeadersSubquery->groupBy('menu_product_id');
            }

            if ($autocomplete) {
                $query = DB::table('inventory.products as p')
                    ->leftJoin('inventory.categories as c', 'c.id', '=', 'p.category_id')
                    ->leftJoin('core.units as u', 'u.id', '=', 'p.unit_id')
                    ->leftJoin('inventory.product_lines as pl', 'pl.id', '=', 'p.line_id')
                    ->select([
                        'p.id',
                        'p.sku',
                        'p.barcode',
                        'p.unit_id',
                        'p.name',
                        'p.sale_price',
                        'p.cost_price',
                        'p.line_id',
                        'p.brand_id',
                        'p.location_id',
                        'p.warranty_id',
                        'p.product_nature',
                        'p.sunat_code',
                        'p.image_url',
                        'p.seller_commission_percent',
                        'p.is_stockable',
                        'p.lot_tracking',
                        'p.has_expiration',
                        'p.status',
                        DB::raw('c.name as category_name'),
                        DB::raw('pl.name as line_name'),
                        DB::raw('NULL::text as brand_name'),
                        DB::raw('NULL::text as location_name'),
                        DB::raw('NULL::text as warranty_name'),
                        DB::raw('u.code as unit_code'),
                        DB::raw('u.name as unit_name'),
                    ])
                    ->where('p.company_id', $companyId)
                    ->whereNull('p.deleted_at');

                if ($search !== '') {
                    $searchPattern = $normalizedSearch . '%';

                    $query->where(function ($nested) use ($searchPattern) {
                        $nested->whereRaw('lower(p.name) like ?', [$searchPattern])
                            ->orWhereRaw('lower(p.sku) like ?', [$searchPattern])
                            ->orWhereRaw('lower(p.barcode) like ?', [$searchPattern]);
                    });

                    $query->orderByRaw(
                        'CASE
                            WHEN lower(p.sku) = ? THEN 0
                            WHEN lower(p.name) = ? THEN 1
                            WHEN lower(p.barcode) = ? THEN 2
                            ELSE 3
                        END',
                        [$normalizedSearch, $normalizedSearch, $normalizedSearch]
                    );
                }

                if ($status !== null && $status !== '') {
                    $query->where('p.status', (int) $status);
                }

                $query->orderBy('p.name')
                    ->limit($limit);

                return $query->get()->all();
            }

            $query = DB::table('inventory.products as p')
                ->leftJoin('inventory.categories as c', 'c.id', '=', 'p.category_id')
                ->leftJoin('core.units as u', 'u.id', '=', 'p.unit_id')
                ->leftJoin('inventory.product_lines as pl', 'pl.id', '=', 'p.line_id')
                ->leftJoin('inventory.product_brands as pb', 'pb.id', '=', 'p.brand_id')
                ->leftJoin('inventory.product_locations as plo', 'plo.id', '=', 'p.location_id')
                ->leftJoin('inventory.product_warranties as pw', 'pw.id', '=', 'p.warranty_id')
                ->select([
                    'p.id',
                    'p.sku',
                    'p.barcode',
                    'p.unit_id',
                    'p.name',
                    'p.sale_price',
                    'p.cost_price',
                    'p.line_id',
                    'p.brand_id',
                    'p.location_id',
                    'p.warranty_id',
                    'p.product_nature',
                    'p.sunat_code',
                    'p.image_url',
                    'p.seller_commission_percent',
                    'p.is_stockable',
                    'p.lot_tracking',
                    'p.has_expiration',
                    'p.status',
                    DB::raw('c.name as category_name'),
                    DB::raw('pl.name as line_name'),
                    DB::raw('pb.name as brand_name'),
                    DB::raw('plo.name as location_name'),
                    DB::raw('pw.name as warranty_name'),
                    DB::raw('u.code as unit_code'),
                    DB::raw('u.name as unit_name'),
                    $recipeFlagSelect,
                ])
                ->where('p.company_id', $companyId)
                ->whereNull('p.deleted_at');

            if ($recipeHeadersSubquery !== null) {
                $query->leftJoinSub($recipeHeadersSubquery, 'rr', function ($join) {
                    $join->on('rr.menu_product_id', '=', 'p.id');
                });
            }

            if ($search !== '') {
                $searchPattern = '%' . $normalizedSearch . '%';

                $query->where(function ($nested) use ($searchPattern) {
                    $nested->whereRaw('lower(p.name) like ?', [$searchPattern])
                        ->orWhereRaw('lower(p.sku) like ?', [$searchPattern])
                        ->orWhereRaw('lower(p.barcode) like ?', [$searchPattern]);
                });
            }

            if ($status !== null && $status !== '') {
                $query->where('p.status', (int) $status);
            }

            $query->orderBy('p.name')
                ->limit($limit);

            return $query->get()->all();
        });
    }
}