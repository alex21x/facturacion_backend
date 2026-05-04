<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tables = Illuminate\Support\Facades\DB::select(
    "select table_schema, table_name from information_schema.tables where table_schema in ('appcfg','restaurant') and (table_name ilike '%vertical%' or table_name ilike '%company%' or table_name ilike '%recipe%' or table_name in ('tables')) order by table_schema, table_name"
);

echo "== TABLES ==\n";
foreach ($tables as $row) {
    echo $row->table_schema . '.' . $row->table_name . "\n";
}

echo "\n== COMPANIES ==\n";
$companies = Illuminate\Support\Facades\DB::select(
    "select id, legal_name, status from core.companies order by id"
);
foreach ($companies as $c) {
    echo sprintf("%d | %s | status=%s\n", (int) $c->id, (string) $c->legal_name, (string) $c->status);
}

echo "\n== RESTAURANT VERTICAL COMPANIES ==\n";
$cvColumns = Illuminate\Support\Facades\DB::select(
    "select column_name from information_schema.columns where table_schema='appcfg' and table_name='company_verticals' order by ordinal_position"
);
echo "company_verticals columns: " . implode(', ', array_map(static fn ($c) => $c->column_name, $cvColumns)) . "\n";

$verticalCompanies = Illuminate\Support\Facades\DB::select(
    "select c.id as company_id, c.legal_name, v.code as vertical_code, cv.is_primary\n"
    . "from core.companies c\n"
    . "join appcfg.company_verticals cv on cv.company_id = c.id\n"
    . "join appcfg.verticals v on v.id = cv.vertical_id and coalesce(v.status,1)=1\n"
    . "where coalesce(c.status,1)=1\n"
    . "order by c.id, cv.is_primary desc"
);
foreach ($verticalCompanies as $row) {
    echo sprintf(
        "%d | %s | %s | primary=%s\n",
        (int) $row->company_id,
        (string) $row->legal_name,
        (string) $row->vertical_code,
        (string) $row->is_primary
    );
}

echo "\n== RESTAURANT DATA COUNTS ==\n";
$hasRecipesTable = Illuminate\Support\Facades\DB::table('information_schema.tables')
    ->where('table_schema', 'restaurant')
    ->where('table_name', 'product_recipes')
    ->exists();

$hasRecipeLinesTable = Illuminate\Support\Facades\DB::table('information_schema.tables')
    ->where('table_schema', 'restaurant')
    ->where('table_name', 'product_recipe_lines')
    ->exists();

$restaurantCompanies = collect($verticalCompanies)
    ->filter(static fn ($r) => strtoupper((string) $r->vertical_code) === 'RESTAURANT')
    ->pluck('company_id')
    ->unique()
    ->values();

foreach ($restaurantCompanies as $companyId) {
    $tablesCount = (int) Illuminate\Support\Facades\DB::table('restaurant.tables')
        ->where('company_id', (int) $companyId)
        ->count();

    $productsCount = (int) Illuminate\Support\Facades\DB::table('inventory.products')
        ->where('company_id', (int) $companyId)
        ->whereNull('deleted_at')
        ->where('product_nature', 'PRODUCT')
        ->count();

    $recipesCount = 0;
    $recipeLinesCount = 0;
    if ($hasRecipesTable) {
        $recipesCount = (int) Illuminate\Support\Facades\DB::table('restaurant.product_recipes')
            ->where('company_id', (int) $companyId)
            ->count();
    }
    if ($hasRecipeLinesTable) {
        $recipeLinesCount = (int) Illuminate\Support\Facades\DB::table('restaurant.product_recipe_lines as rl')
            ->join('restaurant.product_recipes as rh', 'rh.id', '=', 'rl.recipe_id')
            ->where('rh.company_id', (int) $companyId)
            ->count();
    }

    echo sprintf(
        "%d | tables=%d | products=%d | recipes=%d | recipe_lines=%d\n",
        (int) $companyId,
        $tablesCount,
        $productsCount,
        $recipesCount,
        $recipeLinesCount
    );
}
