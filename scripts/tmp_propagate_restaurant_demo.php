<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$sourceCompanyId = 4; // Temporary demo source company.

$restaurantCompanyIds = collect(DB::select(
    "select distinct c.id as company_id
     from core.companies c
     join appcfg.company_verticals cv on cv.company_id = c.id and coalesce(cv.status, 1) = 1
     join appcfg.verticals v on v.id = cv.vertical_id and coalesce(v.status, 1) = 1
     where coalesce(c.status, 1) = 1
       and upper(v.code) = 'RESTAURANT'
     order by c.id"
))->pluck('company_id')->map(static fn ($v) => (int) $v)->values();

if (!$restaurantCompanyIds->contains($sourceCompanyId)) {
    fwrite(STDERR, "Source company {$sourceCompanyId} is not in RESTAURANT vertical list.\n");
    exit(1);
}

$targetCompanyIds = $restaurantCompanyIds->reject(static fn ($id) => (int) $id === $sourceCompanyId)->values();

$sourceTables = DB::table('restaurant.tables')
    ->where('company_id', $sourceCompanyId)
    ->get();

$sourceProducts = DB::table('inventory.products')
    ->where('company_id', $sourceCompanyId)
    ->whereNull('deleted_at')
    ->whereIn('product_nature', ['PRODUCT', 'SUPPLY'])
    ->orderBy('id')
    ->get();

$sourceCategoryIds = $sourceProducts->pluck('category_id')->filter()->unique()->values();
$sourceLineIds = $sourceProducts->pluck('line_id')->filter()->unique()->values();
$sourceBrandIds = $sourceProducts->pluck('brand_id')->filter()->unique()->values();
$sourceLocationIds = $sourceProducts->pluck('location_id')->filter()->unique()->values();
$sourceWarrantyIds = $sourceProducts->pluck('warranty_id')->filter()->unique()->values();

$sourceCategories = DB::table('inventory.categories')->whereIn('id', $sourceCategoryIds)->get()->keyBy('id');
$sourceLines = DB::table('inventory.product_lines')->whereIn('id', $sourceLineIds)->get()->keyBy('id');
$sourceBrands = DB::table('inventory.product_brands')->whereIn('id', $sourceBrandIds)->get()->keyBy('id');
$sourceLocations = DB::table('inventory.product_locations')->whereIn('id', $sourceLocationIds)->get()->keyBy('id');
$sourceWarranties = DB::table('inventory.product_warranties')->whereIn('id', $sourceWarrantyIds)->get()->keyBy('id');

$summary = [];

DB::transaction(function () use (
    $targetCompanyIds,
    $sourceTables,
    $sourceProducts,
    $sourceCategories,
    $sourceLines,
    $sourceBrands,
    $sourceLocations,
    $sourceWarranties,
    &$summary
) {
    foreach ($targetCompanyIds as $targetCompanyId) {
        $targetCompanyId = (int) $targetCompanyId;

        $createdTables = 0;
        $updatedTables = 0;
        $createdProducts = 0;

        foreach ($sourceTables as $srcTable) {
            $existing = DB::table('restaurant.tables')
                ->where('company_id', $targetCompanyId)
                ->where('code', $srcTable->code)
                ->first();

            $payload = [
                'name' => $srcTable->name,
                'capacity' => (int) $srcTable->capacity,
                'status' => $srcTable->status,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('restaurant.tables')
                    ->where('id', $existing->id)
                    ->update($payload);
                $updatedTables++;
            } else {
                DB::table('restaurant.tables')->insert([
                    'company_id' => $targetCompanyId,
                    'branch_id' => $srcTable->branch_id,
                    'code' => $srcTable->code,
                    'name' => $srcTable->name,
                    'capacity' => (int) $srcTable->capacity,
                    'status' => $srcTable->status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdTables++;
            }
        }

        $categoryMap = [];
        $lineMap = [];
        $brandMap = [];
        $locationMap = [];
        $warrantyMap = [];

        foreach ($sourceProducts as $srcProduct) {
            $targetCategoryId = null;
            if ($srcProduct->category_id && $sourceCategories->has($srcProduct->category_id)) {
                $targetCategoryId = ensureMasterByName(
                    'inventory.categories',
                    $targetCompanyId,
                    (string) $sourceCategories[$srcProduct->category_id]->name,
                    $categoryMap
                );
            }

            $targetLineId = null;
            if ($srcProduct->line_id && $sourceLines->has($srcProduct->line_id)) {
                $targetLineId = ensureMasterByName(
                    'inventory.product_lines',
                    $targetCompanyId,
                    (string) $sourceLines[$srcProduct->line_id]->name,
                    $lineMap
                );
            }

            $targetBrandId = null;
            if ($srcProduct->brand_id && $sourceBrands->has($srcProduct->brand_id)) {
                $targetBrandId = ensureMasterByName(
                    'inventory.product_brands',
                    $targetCompanyId,
                    (string) $sourceBrands[$srcProduct->brand_id]->name,
                    $brandMap
                );
            }

            $targetLocationId = null;
            if ($srcProduct->location_id && $sourceLocations->has($srcProduct->location_id)) {
                $targetLocationId = ensureMasterByName(
                    'inventory.product_locations',
                    $targetCompanyId,
                    (string) $sourceLocations[$srcProduct->location_id]->name,
                    $locationMap
                );
            }

            $targetWarrantyId = null;
            if ($srcProduct->warranty_id && $sourceWarranties->has($srcProduct->warranty_id)) {
                $targetWarrantyId = ensureMasterByName(
                    'inventory.product_warranties',
                    $targetCompanyId,
                    (string) $sourceWarranties[$srcProduct->warranty_id]->name,
                    $warrantyMap
                );
            }

            $existingProduct = DB::table('inventory.products')
                ->where('company_id', $targetCompanyId)
                ->whereRaw('LOWER(name) = LOWER(?)', [$srcProduct->name])
                ->where('product_nature', $srcProduct->product_nature)
                ->whereNull('deleted_at')
                ->first();

            if ($existingProduct) {
                continue;
            }

            DB::table('inventory.products')->insert([
                'company_id' => $targetCompanyId,
                'sku' => $srcProduct->sku,
                'barcode' => $srcProduct->barcode,
                'category_id' => $targetCategoryId,
                'unit_id' => $srcProduct->unit_id,
                'name' => $srcProduct->name,
                'sale_price' => $srcProduct->sale_price,
                'cost_price' => $srcProduct->cost_price,
                'is_stockable' => (bool) $srcProduct->is_stockable,
                'lot_tracking' => (bool) $srcProduct->lot_tracking,
                'has_expiration' => (bool) $srcProduct->has_expiration,
                'status' => (int) $srcProduct->status,
                'line_id' => $targetLineId,
                'brand_id' => $targetBrandId,
                'location_id' => $targetLocationId,
                'warranty_id' => $targetWarrantyId,
                'product_nature' => $srcProduct->product_nature,
                'sunat_code' => $srcProduct->sunat_code,
                'image_url' => $srcProduct->image_url,
                'seller_commission_percent' => $srcProduct->seller_commission_percent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $createdProducts++;
        }

        $summary[] = [
            'company_id' => $targetCompanyId,
            'tables_created' => $createdTables,
            'tables_updated' => $updatedTables,
            'products_created' => $createdProducts,
        ];
    }
});

echo "Temporary demo propagation completed.\n";
echo "Source company: {$sourceCompanyId}\n";
echo "Targets: " . $targetCompanyIds->implode(', ') . "\n\n";

foreach ($summary as $row) {
    echo sprintf(
        "company=%d | tables_created=%d | tables_updated=%d | products_created=%d\n",
        $row['company_id'],
        $row['tables_created'],
        $row['tables_updated'],
        $row['products_created']
    );
}

function ensureMasterByName(string $table, int $companyId, string $name, array &$cache): ?int
{
    $normalized = mb_strtolower(trim($name));
    if ($normalized === '') {
        return null;
    }

    $cacheKey = $table . ':' . $normalized;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $existing = DB::table($table)
        ->where('company_id', $companyId)
        ->whereRaw('LOWER(name) = LOWER(?)', [$name])
        ->first();

    if ($existing) {
        $cache[$cacheKey] = (int) $existing->id;
        return $cache[$cacheKey];
    }

    $id = (int) DB::table($table)->insertGetId([
        'company_id' => $companyId,
        'name' => trim($name),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cache[$cacheKey] = $id;
    return $id;
}
