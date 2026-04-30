<?php

namespace App\Services\Restaurant;

use Illuminate\Support\Facades\DB;

class RestaurantRecipeService
{
    private const EPSILON = 0.00000001;
    private const PREP_LEDGER_REF_TYPE = 'RESTAURANT_PREP';

    public function upsertRecipe(int $companyId, int $menuProductId, array $lines, ?string $notes = null): array
    {
        $this->ensureStorage();

        $menuProduct = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->where('id', $menuProductId)
            ->first(['id', 'name', 'status']);

        if (!$menuProduct || (int) ($menuProduct->status ?? 0) !== 1) {
            throw new \RuntimeException('Producto de menu no valido para receta', 422);
        }

        if (count($lines) === 0) {
            throw new \RuntimeException('La receta debe tener al menos un insumo', 422);
        }

        $ingredientIds = collect($lines)
            ->pluck('ingredient_product_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (count($ingredientIds) !== count($lines)) {
            throw new \RuntimeException('No se permiten insumos repetidos en la receta', 422);
        }

        $validIngredients = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->whereIn('id', $ingredientIds)
            ->where('status', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $validMap = array_fill_keys($validIngredients, true);
        foreach ($ingredientIds as $ingredientId) {
            if (!isset($validMap[$ingredientId])) {
                throw new \RuntimeException('Insumo no valido en receta: #' . $ingredientId, 422);
            }
        }

        DB::transaction(function () use ($companyId, $menuProductId, $lines, $notes): void {
            $header = DB::table('restaurant.product_recipes')
                ->where('company_id', $companyId)
                ->where('menu_product_id', $menuProductId)
                ->first(['id']);

            if ($header) {
                $recipeId = (int) $header->id;
                DB::table('restaurant.product_recipes')
                    ->where('id', $recipeId)
                    ->where('company_id', $companyId)
                    ->update([
                        'is_active' => true,
                        'notes' => $notes,
                        'updated_at' => now(),
                    ]);
            } else {
                $recipeId = (int) DB::table('restaurant.product_recipes')->insertGetId([
                    'company_id' => $companyId,
                    'menu_product_id' => $menuProductId,
                    'is_active' => true,
                    'notes' => $notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('restaurant.product_recipe_items')
                ->where('recipe_id', $recipeId)
                ->delete();

            $rows = [];
            foreach (array_values($lines) as $idx => $line) {
                $qtyRequired = round((float) ($line['qty_required_base'] ?? 0), 8);
                $wastage = round((float) ($line['wastage_percent'] ?? 0), 4);

                if ($qtyRequired <= self::EPSILON) {
                    throw new \RuntimeException('Cantidad requerida invalida en receta', 422);
                }

                if ($wastage < 0 || $wastage > 100) {
                    throw new \RuntimeException('Merma invalida en receta', 422);
                }

                $rows[] = [
                    'recipe_id' => $recipeId,
                    'ingredient_product_id' => (int) $line['ingredient_product_id'],
                    'qty_required_base' => $qtyRequired,
                    'wastage_percent' => $wastage,
                    'sort_order' => $idx + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('restaurant.product_recipe_items')->insert($rows);
        });

        return $this->getRecipe($companyId, $menuProductId);
    }

    public function getRecipe(int $companyId, int $menuProductId): array
    {
        $this->ensureStorage();

        $header = DB::table('restaurant.product_recipes as r')
            ->join('inventory.products as p', function ($join) {
                $join->on('p.id', '=', 'r.menu_product_id')
                    ->on('p.company_id', '=', 'r.company_id');
            })
            ->where('r.company_id', $companyId)
            ->where('r.menu_product_id', $menuProductId)
            ->first([
                'r.id',
                'r.menu_product_id',
                'r.is_active',
                'r.notes',
                'p.name as menu_product_name',
                'p.sku as menu_product_code',
            ]);

        if (!$header) {
            throw new \RuntimeException('Receta no encontrada para el producto seleccionado', 404);
        }

        $lines = DB::table('restaurant.product_recipe_items as ri')
            ->join('inventory.products as p', function ($join) {
                $join->on('p.id', '=', 'ri.ingredient_product_id');
            })
            ->where('ri.recipe_id', (int) $header->id)
            ->orderBy('ri.sort_order')
            ->orderBy('ri.id')
            ->get([
                'ri.ingredient_product_id',
                'ri.qty_required_base',
                'ri.wastage_percent',
                'p.sku as ingredient_code',
                'p.name as ingredient_name',
            ]);

        return [
            'recipe_id' => (int) $header->id,
            'menu_product_id' => (int) $header->menu_product_id,
            'menu_product_code' => (string) ($header->menu_product_code ?? ''),
            'menu_product_name' => (string) ($header->menu_product_name ?? ''),
            'is_active' => (bool) $header->is_active,
            'notes' => $header->notes,
            'lines' => collect($lines)->map(function ($line) {
                return [
                    'ingredient_product_id' => (int) $line->ingredient_product_id,
                    'ingredient_code' => (string) ($line->ingredient_code ?? ''),
                    'ingredient_name' => (string) ($line->ingredient_name ?? ''),
                    'qty_required_base' => round((float) $line->qty_required_base, 8),
                    'wastage_percent' => round((float) $line->wastage_percent, 4),
                ];
            })->values()->all(),
        ];
    }

    public function resolvePreparationRequirements(int $companyId, int $orderId): array
    {
        $this->ensureStorage();

        $order = DB::table('sales.commercial_documents')
            ->where('id', $orderId)
            ->where('company_id', $companyId)
            ->first(['id', 'branch_id', 'warehouse_id', 'document_kind', 'status']);

        if (!$order) {
            throw new \RuntimeException('Pedido no encontrado', 404);
        }

        if ((string) $order->document_kind !== 'SALES_ORDER') {
            throw new \RuntimeException('Solo aplica para pedidos de restaurante', 422);
        }

        // If RESTAURANT_RECIPES_ENABLED flag is OFF (branch override first, then company),
        // skip all recipe/stock validation and let the comanda flow freely.
        $rawCompanyToggle = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', 'RESTAURANT_RECIPES_ENABLED')
            ->value('is_enabled');

        $rawBranchToggle = null;
        if ($order->branch_id !== null) {
            $rawBranchToggle = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', (int) $order->branch_id)
                ->where('feature_code', 'RESTAURANT_RECIPES_ENABLED')
                ->value('is_enabled');
        }

        $recipesEnabled = $rawBranchToggle !== null
            ? $this->toBoolFlag($rawBranchToggle)
            : $this->toBoolFlag($rawCompanyToggle);

        if (!$recipesEnabled) {
            return [
                'order_id'            => $orderId,
                'warehouse_id'        => null,
                'menu_items'          => [],
                'ingredients_summary' => [],
                'can_prepare'         => true,
            ];
        }

        if ($order->warehouse_id === null) {
            throw new \RuntimeException('El pedido no tiene almacen asignado para validar recetas', 422);
        }

        $warehouseId = (int) $order->warehouse_id;

        $orderItems = DB::table('sales.commercial_document_items as i')
            ->leftJoin('inventory.products as p', function ($join) {
                $join->on('p.id', '=', 'i.product_id');
            })
            ->where('i.document_id', $orderId)
            ->whereNotNull('i.product_id')
            ->get([
                'i.product_id',
                'i.qty',
                'i.qty_base',
                'i.conversion_factor',
                'p.name as product_name',
                'p.sku as product_code',
            ]);

        if ($orderItems->isEmpty()) {
            return [
                'order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'menu_items' => [],
                'ingredients_summary' => [],
                'can_prepare' => true,
            ];
        }

        $menuItems = [];
        $menuProductIds = [];

        foreach ($orderItems as $item) {
            $menuProductId = (int) ($item->product_id ?? 0);
            if ($menuProductId <= 0) {
                continue;
            }

            $qty = (float) ($item->qty ?? 0);
            $factor = (float) ($item->conversion_factor ?? 1);
            if ($factor <= self::EPSILON) {
                $factor = 1;
            }

            $qtyBase = (float) ($item->qty_base ?? 0);
            if ($qtyBase <= self::EPSILON) {
                $qtyBase = $qty * $factor;
            }

            if ($qtyBase <= self::EPSILON) {
                continue;
            }

            $menuItems[] = [
                'menu_product_id' => $menuProductId,
                'menu_product_name' => (string) ($item->product_name ?? ''),
                'menu_product_code' => (string) ($item->product_code ?? ''),
                'order_qty_base' => round($qtyBase, 8),
            ];

            $menuProductIds[$menuProductId] = true;
        }

        if (count($menuItems) === 0) {
            return [
                'order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'menu_items' => [],
                'ingredients_summary' => [],
                'can_prepare' => true,
            ];
        }

        $menuIds = array_keys($menuProductIds);

        $recipeHeaders = DB::table('restaurant.product_recipes')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('menu_product_id', $menuIds)
            ->get(['id', 'menu_product_id']);

        $recipeByMenu = [];
        foreach ($recipeHeaders as $recipeHeader) {
            $recipeByMenu[(int) $recipeHeader->menu_product_id] = (int) $recipeHeader->id;
        }

        $recipeItems = DB::table('restaurant.product_recipe_items')
            ->whereIn('recipe_id', array_values($recipeByMenu))
            ->orderBy('sort_order')
            ->get(['recipe_id', 'ingredient_product_id', 'qty_required_base', 'wastage_percent']);

        $recipeItemsByRecipe = [];
        foreach ($recipeItems as $recipeItem) {
            $recipeId = (int) $recipeItem->recipe_id;
            $recipeItemsByRecipe[$recipeId][] = [
                'ingredient_product_id' => (int) $recipeItem->ingredient_product_id,
                'qty_required_base' => round((float) $recipeItem->qty_required_base, 8),
                'wastage_percent' => round((float) $recipeItem->wastage_percent, 4),
            ];
        }

        $ingredientRequired = [];
        $menuDetails = [];

        foreach ($menuItems as $menuItem) {
            $recipeId = $recipeByMenu[$menuItem['menu_product_id']] ?? null;
            $detailIngredients = [];

            if ($recipeId === null) {
                throw new \RuntimeException('Falta receta para: ' . $menuItem['menu_product_name'], 422);
            }

            $recipeLines = $recipeItemsByRecipe[$recipeId] ?? [];
            if (count($recipeLines) === 0) {
                throw new \RuntimeException('La receta no tiene insumos definidos para: ' . $menuItem['menu_product_name'], 422);
            }

            foreach ($recipeLines as $line) {
                $baseRequired = $menuItem['order_qty_base'] * $line['qty_required_base'];
                $wastageMultiplier = 1 + (max(0.0, $line['wastage_percent']) / 100.0);
                $required = round($baseRequired * $wastageMultiplier, 8);

                if ($required <= self::EPSILON) {
                    continue;
                }

                $ingredientId = (int) $line['ingredient_product_id'];
                if (!isset($ingredientRequired[$ingredientId])) {
                    $ingredientRequired[$ingredientId] = 0.0;
                }
                $ingredientRequired[$ingredientId] += $required;

                $detailIngredients[] = [
                    'ingredient_product_id' => $ingredientId,
                    'required_base' => round($required, 8),
                ];
            }

            $menuDetails[] = [
                'menu_product_id' => $menuItem['menu_product_id'],
                'menu_product_name' => $menuItem['menu_product_name'],
                'menu_product_code' => $menuItem['menu_product_code'],
                'order_qty_base' => $menuItem['order_qty_base'],
                'ingredients' => $detailIngredients,
            ];
        }

        $ingredientIds = array_keys($ingredientRequired);
        if (count($ingredientIds) === 0) {
            return [
                'order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'menu_items' => $menuDetails,
                'ingredients_summary' => [],
                'can_prepare' => true,
            ];
        }

        $ingredientMetaRows = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->whereIn('id', $ingredientIds)
            ->get(['id', 'sku as code', 'name']);

        $ingredientMeta = [];
        foreach ($ingredientMetaRows as $ingredientMetaRow) {
            $ingredientMeta[(int) $ingredientMetaRow->id] = [
                'code' => (string) ($ingredientMetaRow->code ?? ''),
                'name' => (string) ($ingredientMetaRow->name ?? ''),
            ];
        }

        $stockRows = DB::table('inventory.current_stock')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $ingredientIds)
            ->get(['product_id', 'stock']);

        $availableByProduct = [];
        foreach ($stockRows as $stockRow) {
            $availableByProduct[(int) $stockRow->product_id] = (float) ($stockRow->stock ?? 0);
        }

        $ingredientsSummary = [];
        $canPrepare = true;

        foreach ($ingredientIds as $ingredientId) {
            $required = round((float) ($ingredientRequired[$ingredientId] ?? 0), 8);
            $available = round((float) ($availableByProduct[$ingredientId] ?? 0), 8);
            $shortfall = round(max(0, $required - $available), 8);
            if ($shortfall > self::EPSILON) {
                $canPrepare = false;
            }

            $meta = $ingredientMeta[$ingredientId] ?? ['code' => '', 'name' => ''];
            $ingredientsSummary[] = [
                'ingredient_product_id' => (int) $ingredientId,
                'ingredient_code' => (string) $meta['code'],
                'ingredient_name' => (string) $meta['name'],
                'required_base' => $required,
                'available_base' => $available,
                'shortfall_base' => $shortfall,
            ];
        }

        usort($ingredientsSummary, function (array $a, array $b): int {
            return $b['shortfall_base'] <=> $a['shortfall_base'];
        });

        return [
            'order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'menu_items' => $menuDetails,
            'ingredients_summary' => $ingredientsSummary,
            'can_prepare' => $canPrepare,
        ];
    }

    public function assertOrderCanEnterPreparation(int $companyId, int $orderId): void
    {
        $requirements = $this->resolvePreparationRequirements($companyId, $orderId);

        if ((bool) ($requirements['can_prepare'] ?? false)) {
            return;
        }

        $deficits = collect($requirements['ingredients_summary'] ?? [])
            ->filter(fn (array $line) => (float) ($line['shortfall_base'] ?? 0) > self::EPSILON)
            ->take(3)
            ->map(function (array $line): string {
                $name = trim((string) ($line['ingredient_name'] ?? ''));
                if ($name === '') {
                    $name = '#' . (int) ($line['ingredient_product_id'] ?? 0);
                }

                $shortfall = number_format((float) ($line['shortfall_base'] ?? 0), 3, '.', '');
                return $name . ' (falta ' . $shortfall . ')';
            })
            ->values()
            ->all();

        throw new \RuntimeException(
            'Stock insuficiente para iniciar preparación: ' . implode(', ', $deficits),
            422
        );
    }

    public function consumePreparationInventory(int $companyId, int $orderId): array
    {
        $requirements = $this->resolvePreparationRequirements($companyId, $orderId);

        $summary = is_array($requirements['ingredients_summary'] ?? null)
            ? $requirements['ingredients_summary']
            : [];

        if (count($summary) === 0) {
            return [
                'applied' => false,
                'reason' => 'no_ingredients',
                'rows' => 0,
            ];
        }

        $alreadyApplied = DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', self::PREP_LEDGER_REF_TYPE)
            ->where('ref_id', $orderId)
            ->exists();

        if ($alreadyApplied) {
            return [
                'applied' => false,
                'reason' => 'already_applied',
                'rows' => 0,
            ];
        }

        $warehouseId = (int) ($requirements['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new \RuntimeException('No se pudo resolver almacen para consumo de receta', 422);
        }

        $productIds = collect($summary)
            ->pluck('ingredient_product_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $costMap = DB::table('inventory.products')
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->pluck('cost_price', 'id')
            ->map(fn ($cost) => (float) ($cost ?? 0))
            ->all();

        $now = now();
        $ledgerRows = [];

        foreach ($summary as $line) {
            $productId = (int) ($line['ingredient_product_id'] ?? 0);
            $required = round((float) ($line['required_base'] ?? 0), 8);

            if ($productId <= 0 || $required <= self::EPSILON) {
                continue;
            }

            $ingredientName = trim((string) ($line['ingredient_name'] ?? ''));
            $ledgerRows[] = [
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'lot_id' => null,
                'movement_type' => 'OUT',
                'quantity' => $required,
                'unit_cost' => (float) ($costMap[$productId] ?? 0),
                'ref_type' => self::PREP_LEDGER_REF_TYPE,
                'ref_id' => $orderId,
                'notes' => 'Salida por preparación de comanda #' . $orderId . ($ingredientName !== '' ? (' · ' . $ingredientName) : ''),
                'moved_at' => $now,
                'created_by' => null,
            ];
        }

        if (count($ledgerRows) > 0) {
            DB::table('inventory.inventory_ledger')->insert($ledgerRows);
        }

        return [
            'applied' => true,
            'reason' => 'ok',
            'rows' => count($ledgerRows),
        ];
    }

    private function toBoolFlag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '' || in_array($normalized, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }

        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on'], true);
    }

    private function ensureStorage(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS restaurant');

        DB::statement(
            'CREATE TABLE IF NOT EXISTS restaurant.product_recipes (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                menu_product_id BIGINT NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                notes VARCHAR(300) NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (company_id, menu_product_id)
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS restaurant.product_recipe_items (
                id BIGSERIAL PRIMARY KEY,
                recipe_id BIGINT NOT NULL,
                ingredient_product_id BIGINT NOT NULL,
                qty_required_base NUMERIC(18,8) NOT NULL,
                wastage_percent NUMERIC(8,4) NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (recipe_id, ingredient_product_id),
                CONSTRAINT fk_restaurant_recipe_items_recipe
                    FOREIGN KEY (recipe_id) REFERENCES restaurant.product_recipes(id) ON DELETE CASCADE
            )'
        );

        DB::statement('CREATE INDEX IF NOT EXISTS idx_restaurant_product_recipes_company_menu ON restaurant.product_recipes (company_id, menu_product_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_restaurant_recipe_items_recipe_sort ON restaurant.product_recipe_items (recipe_id, sort_order)');
    }
}
