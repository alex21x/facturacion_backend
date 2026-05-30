<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\CheckoutRestaurantOrderRequest;
use App\Http\Requests\Restaurant\CreateRestaurantOrderRequest;
use App\Http\Requests\Restaurant\CreateRestaurantTableRequest;
use App\Http\Requests\Restaurant\UpdateComandaStatusRequest;
use App\Http\Requests\Restaurant\UpdateRestaurantTableRequest;
use App\Http\Requests\Restaurant\UpsertRecipeRequest;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Services\Restaurant\RestaurantComandaGateway;
use App\Services\Restaurant\RestaurantOrderService;
use App\Services\Restaurant\RestaurantRecipeService;
use App\Services\Sales\SalesLookupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RestaurantController extends Controller
{
    public function __construct(
        private RestaurantComandaGateway $gateway,
        private RestaurantOrderService $orderService,
        private RestaurantRecipeService $recipeService,
        private CompanyIgvRateService $companyIgvRateService,
        private SalesLookupService $salesLookupService
    ) {
    }

    // =========================================================================
    // Restaurant order endpoints (vertical-specific, does NOT touch retail)
    // =========================================================================

    public function bootstrap(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id');
        $mode = (string) $request->query('mode', 'full');

        if (!in_array($mode, ['full', 'orders_minimal'], true)) {
            $mode = 'full';
        }

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
            $branchExists = $this->salesLookupService->branchExists($companyId, $branchId);

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        } else {
            $branchId = null;
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $warehouseId = (int) $warehouseId;
        } else {
            $warehouseId = null;
        }

        $cacheKey = sprintf(
            'restaurant_bootstrap:%d:%s:%s:%s',
            $companyId,
            $branchId ?? 'all',
            $warehouseId ?? 'all',
            $mode
        );

        $payload = Cache::remember($cacheKey, now()->addSeconds(20), function () use ($companyId, $branchId, $warehouseId, $mode) {
            $currencies = $this->salesLookupService->listActiveCurrencies();
            $paymentMethods = $this->salesLookupService->listActivePaymentTypes();

            $allowedKinds = $mode === 'orders_minimal'
                ? ['SALES_ORDER']
                : ['SALES_ORDER', 'INVOICE', 'RECEIPT'];
            $documentKindIdsByCode = $this->salesLookupService->resolveDocumentKindIdMapByCodes($allowedKinds);
            $allowedKindIds = $this->salesLookupService->resolveDocumentKindIdsByCodes($allowedKinds);
            $allowedKindAliases = $this->salesLookupService->resolveDocumentKindAliasesByCodes($allowedKinds);
            $seriesNumbers = $this->salesLookupService
                ->listSeriesNumbers($companyId, $branchId, $warehouseId, true, null, null)
                ->filter(function ($row) use ($allowedKindIds, $allowedKindAliases) {
                    $documentKindId = isset($row->document_kind_id) && $row->document_kind_id !== null
                        ? (int) $row->document_kind_id
                        : null;

                    if ($documentKindId !== null && in_array($documentKindId, $allowedKindIds, true)) {
                        return true;
                    }

                    $documentKindAlias = strtoupper(trim((string) ($row->document_kind ?? '')));
                    return in_array($documentKindAlias, $allowedKindAliases, true);
                })
                ->map(function ($row) {
                $documentKindId = isset($row->document_kind_id) && $row->document_kind_id !== null
                    ? (int) $row->document_kind_id
                    : null;

                $normalizedCode = $this->salesLookupService->resolveCanonicalDocumentKindCode(
                    (string) ($row->document_kind ?? ''),
                    $documentKindId
                );

                if ($normalizedCode !== null) {
                    $row->document_kind = $normalizedCode;
                }

                return $row;
            })->values();

            $companyToggles = $this->salesLookupService->loadCompanyFeatureToggles($companyId)->pluck('is_enabled', 'feature_code');
            $branchToggles = $branchId !== null
                ? $this->salesLookupService->loadBranchFeatureToggles($companyId, $branchId)->pluck('is_enabled', 'feature_code')
                : collect();

            $companyToggle = $companyToggles->get('RESTAURANT_MENU_IGV_INCLUDED');
            $branchToggle = $branchToggles->get('RESTAURANT_MENU_IGV_INCLUDED');

            $restaurantPriceIncludesIgv = $branchToggle !== null
                ? (bool) $branchToggle
                : ($companyToggle !== null ? (bool) $companyToggle : true);

            $recipesCompanyToggle = $companyToggles->get('RESTAURANT_RECIPES_ENABLED');
            $recipesBranchToggle = $branchToggles->get('RESTAURANT_RECIPES_ENABLED');

            $restaurantRecipesEnabled = $recipesBranchToggle !== null
                ? (bool) $recipesBranchToggle
                : ($recipesCompanyToggle !== null ? (bool) $recipesCompanyToggle : false);

            $sellerToCashierCompanyToggle = $companyToggles->get('SALES_SELLER_TO_CASHIER');
            $sellerToCashierBranchToggle = $branchToggles->get('SALES_SELLER_TO_CASHIER');

            $sellerToCashierEnabled = $sellerToCashierBranchToggle !== null
                ? (bool) $sellerToCashierBranchToggle
                : ($sellerToCashierCompanyToggle !== null ? (bool) $sellerToCashierCompanyToggle : false);

            return [
                'currencies' => $currencies,
                'payment_methods' => $paymentMethods,
                'active_igv_rate_percent' => $this->companyIgvRateService->resolveActiveRatePercent($companyId),
                'restaurant_price_includes_igv' => $restaurantPriceIncludesIgv,
                'restaurant_recipes_enabled' => $restaurantRecipesEnabled,
                'sales_seller_to_cashier_enabled' => $sellerToCashierEnabled,
                'document_kind_ids' => $documentKindIdsByCode,
                'series_numbers' => $seriesNumbers,
            ];
        });

        return response()->json($payload);
    }

    public function fetchOrders(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId  = $request->query('branch_id', $authUser->branch_id);
        $status    = strtoupper(trim((string) $request->query('status', '')));
        $search    = trim((string) $request->query('search', ''));
        $page      = max(1, (int) $request->query('page', 1));
        $perPage   = min(50, max(10, (int) $request->query('per_page', 12)));
        $includeItems = filter_var($request->query('include_items', false), FILTER_VALIDATE_BOOLEAN);
        $includeMeta = filter_var($request->query('include_meta', false), FILTER_VALIDATE_BOOLEAN);

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        try {
            $result = $this->orderService->fetchOrders(
                $companyId, $branchId, $status, $search, $page, $perPage, $includeItems, $includeMeta
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function showOrder(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->orderService->fetchOrderDetail($companyId, $id);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json(['data' => $result]);
    }

    public function createOrder(CreateRestaurantOrderRequest $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload   = $request->validated();
        $branchId  = (int) $payload['branch_id'];
        $warehouseId = isset($payload['warehouse_id']) ? (int) $payload['warehouse_id'] : null;

        $branchExists = $this->salesLookupService->branchExists($companyId, $branchId);

        if (!$branchExists) {
            return response()->json(['message' => 'Invalid branch scope'], 422);
        }

        if ($warehouseId !== null) {
            $warehouseExists = $this->salesLookupService->activeWarehouseExistsInBranchScope($companyId, $warehouseId, $branchId);

            if (!$warehouseExists) {
                return response()->json(['message' => 'Invalid warehouse scope'], 422);
            }
        } else {
            $warehouseId = $this->resolveDefaultWarehouseId($companyId, $branchId);

            if ($warehouseId === null) {
                return response()->json([
                    'message' => 'No existe almacén activo para la sucursal seleccionada. Crea uno en Maestros > Almacenes.',
                ], 422);
            }
        }

        try {
            $result = $this->orderService->createOrder(
                $authUser,
                $companyId,
                $branchId,
                $warehouseId,
                $payload
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        } catch (\App\Services\Sales\Documents\SalesDocumentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 422);
        }

        return response()->json($result, 201);
    }

    public function checkoutOrder(CheckoutRestaurantOrderRequest $request, $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $orderId   = (int) $id;

        $payload = $request->validated();

        try {
            $result = $this->orderService->checkoutOrder(
                $orderId,
                $companyId,
                $authUser,
                $payload['target_document_kind'],
                $payload['series'] ?? null,
                isset($payload['cash_register_id']) ? (int) $payload['cash_register_id'] : null,
                isset($payload['payment_method_id']) ? (int) $payload['payment_method_id'] : null,
                $payload['notes'] ?? null
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        } catch (\App\Services\Sales\Documents\SalesDocumentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 422);
        }

        return response()->json($result, 201);
    }

    public function comandas(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $status = strtoupper(trim((string) $request->query('status', '')));
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 20)));

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
            $branchExists = $this->salesLookupService->branchExists($companyId, $branchId);

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        } else {
            $branchId = null;
        }

        try {
            $result = $this->gateway->list(
                $companyId,
                $branchId,
                $status,
                $search,
                $page,
                $perPage,
                $this->resolveBearerToken($request)
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function updateComandaStatus(UpdateComandaStatusRequest $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();

        try {
            $result = $this->gateway->updateStatus(
                $companyId,
                $id,
                (string) $payload['status'],
                array_key_exists('table_label', $payload) ? (string) ($payload['table_label'] ?? '') : null,
                $this->resolveBearerToken($request)
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function getRecipe(Request $request, int $menuProductId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->recipeService->getRecipe($companyId, $menuProductId);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function upsertRecipe(UpsertRecipeRequest $request, int $menuProductId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();

        try {
            $result = $this->recipeService->upsertRecipe(
                $companyId,
                $menuProductId,
                $payload['lines'],
                $payload['notes'] ?? null
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function preparationRequirements(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->recipeService->resolvePreparationRequirements($companyId, $id);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function tables(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $status = strtoupper(trim((string) $request->query('status', '')));
        $search = trim((string) $request->query('search', ''));

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
            $branchExists = $this->salesLookupService->branchExists($companyId, $branchId);

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        } else {
            $branchId = null;
        }

        try {
            $result = $this->gateway->listTables(
                $companyId,
                $branchId,
                $status,
                $search,
                $this->resolveBearerToken($request)
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function createTable(CreateRestaurantTableRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();
        $branchId = (int) $payload['branch_id'];

        $branchExists = $this->salesLookupService->branchExists($companyId, $branchId);

        if (!$branchExists) {
            return response()->json(['message' => 'Invalid branch scope'], 422);
        }

        try {
            $result = $this->gateway->createTable(
                $companyId,
                $branchId,
                strtoupper(trim((string) $payload['code'])),
                trim((string) $payload['name']),
                (int) $payload['capacity'],
                $this->resolveBearerToken($request)
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result, 201);
    }

    public function updateTable(UpdateRestaurantTableRequest $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();

        try {
            $result = $this->gateway->updateTable(
                $companyId,
                $id,
                array_key_exists('name', $payload) ? trim((string) $payload['name']) : null,
                array_key_exists('capacity', $payload) ? (int) $payload['capacity'] : null,
                array_key_exists('status', $payload) ? strtoupper(trim((string) $payload['status'])) : null,
                $this->resolveBearerToken($request)
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    private function resolveBearerToken(Request $request): ?string
    {
        $raw = (string) $request->header('Authorization', '');
        if (stripos($raw, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($raw, 7));
        return $token !== '' ? $token : null;
    }

    private function resolveDocumentKindIdsByCodes(array $codes): array
    {
        return $this->salesLookupService->resolveDocumentKindIdsByCodes($codes);
    }

    private function resolveDefaultWarehouseId(int $companyId, int $branchId): ?int
    {
        return $this->salesLookupService->resolveDefaultWarehouseIdByBranchScope($companyId, $branchId);
    }

    private function resolveDocumentKindAliasesByCodes(array $codes): array
    {
        return $this->salesLookupService->resolveDocumentKindAliasesByCodes($codes);
    }

    private function resolveDocumentKindIdMapByCodes(array $codes): array
    {
        return $this->salesLookupService->resolveDocumentKindIdMapByCodes($codes);
    }

    private function resolveCanonicalDocumentKindCode(string $documentKindValue, ?int $documentKindId = null): ?string
    {
        return $this->salesLookupService->resolveCanonicalDocumentKindCode($documentKindValue, $documentKindId);
    }
}
