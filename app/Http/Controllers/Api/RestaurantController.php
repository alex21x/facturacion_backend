<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Services\Restaurant\RestaurantComandaGateway;
use App\Services\Restaurant\RestaurantOrderService;
use App\Services\Restaurant\RestaurantRecipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RestaurantController extends Controller
{
    public function __construct(
        private RestaurantComandaGateway $gateway,
        private RestaurantOrderService $orderService,
        private RestaurantRecipeService $recipeService,
        private CompanyIgvRateService $companyIgvRateService
    ) {
    }

    // =========================================================================
    // Restaurant order endpoints (vertical-specific, does NOT touch retail)
    // =========================================================================

    public function bootstrap(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id');
        $mode = (string) $request->query('mode', 'full');

        if (!in_array($mode, ['full', 'orders_minimal'], true)) {
            $mode = 'full';
        }

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
            $branchExists = DB::table('core.branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

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
            $currencies = DB::table('core.currencies')
                ->select('id', 'code', 'name', 'symbol', 'is_default')
                ->where('status', 1)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get();

            $paymentMethods = DB::table('master.payment_types')
                ->select([
                    'id',
                    DB::raw("COALESCE(NULLIF(TRIM(comment), ''), CONCAT('PM', id::text)) as code"),
                    'name',
                ])
                ->where(function ($query) {
                    $query->where('is_active', 1)
                        ->orWhereIn('status', [1, 2]);
                })
                ->orderBy('name')
                ->get();

            $allowedKinds = $mode === 'orders_minimal'
                ? ['SALES_ORDER']
                : ['SALES_ORDER', 'INVOICE', 'RECEIPT'];
            $allowedKindIds = $this->resolveDocumentKindIdsByCodes($allowedKinds);
            $allowedKindAliases = $this->resolveDocumentKindAliasesByCodes($allowedKinds);

            $seriesQuery = DB::table('sales.series_numbers as sn')
                ->leftJoin('sales.document_kinds as dk', 'dk.id', '=', 'sn.document_kind_id')
                ->select([
                    'sn.id',
                    'sn.document_kind_id',
                    DB::raw("COALESCE(dk.code, sn.document_kind) as document_kind"),
                    'sn.series',
                    'sn.current_number',
                    'sn.is_enabled',
                ])
                ->where('sn.company_id', $companyId)
                ->where(function ($query) use ($allowedKinds, $allowedKindIds, $allowedKindAliases) {
                    if (!empty($allowedKindIds)) {
                        $query->whereIn('sn.document_kind_id', $allowedKindIds)
                            ->orWhere(function ($legacy) use ($allowedKindAliases) {
                                $legacy->whereNull('sn.document_kind_id')
                                    ->whereIn(DB::raw("UPPER(TRIM(COALESCE(sn.document_kind, '')))") , $allowedKindAliases);
                            });

                        return;
                    }

                    $query->whereIn(DB::raw('UPPER(TRIM(COALESCE(dk.code, sn.document_kind)))'), $allowedKindAliases);
                })
                ->where('sn.is_enabled', true)
                ->orderBy('document_kind')
                ->orderBy('sn.series');

            if ($branchId !== null) {
                $seriesQuery->where('sn.branch_id', $branchId);
            }

            if ($warehouseId !== null) {
                $seriesQuery->where('sn.warehouse_id', $warehouseId);
            }

            $seriesNumbers = $seriesQuery->get();

            $companyToggle = DB::table('appcfg.company_feature_toggles')
                ->where('company_id', $companyId)
                ->where('feature_code', 'RESTAURANT_MENU_IGV_INCLUDED')
                ->value('is_enabled');

            $branchToggle = null;
            if ($branchId !== null) {
                $branchToggle = DB::table('appcfg.branch_feature_toggles')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('feature_code', 'RESTAURANT_MENU_IGV_INCLUDED')
                    ->value('is_enabled');
            }

            $restaurantPriceIncludesIgv = $branchToggle !== null
                ? (bool) $branchToggle
                : ($companyToggle !== null ? (bool) $companyToggle : true);

            return [
                'currencies' => $currencies,
                'payment_methods' => $paymentMethods,
                'active_igv_rate_percent' => $this->companyIgvRateService->resolveActiveRatePercent($companyId),
                'restaurant_price_includes_igv' => $restaurantPriceIncludesIgv,
                'series_numbers' => $seriesNumbers,
            ];
        });

        return response()->json($payload);
    }

    public function fetchOrders(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId  = $request->query('branch_id', $authUser->branch_id);
        $status    = strtoupper(trim((string) $request->query('status', '')));
        $search    = trim((string) $request->query('search', ''));
        $page      = max(1, (int) $request->query('page', 1));
        $perPage   = min(50, max(10, (int) $request->query('per_page', 12)));
        $includeItems = filter_var($request->query('include_items', false), FILTER_VALIDATE_BOOLEAN);
        $includeMeta = filter_var($request->query('include_meta', false), FILTER_VALIDATE_BOOLEAN);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

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
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->orderService->fetchOrderDetail($companyId, $id);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json(['data' => $result]);
    }

    public function createOrder(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'branch_id'        => 'required|integer|min:1',
            'warehouse_id'     => 'nullable|integer|min:1',
            'table_id'         => 'nullable|integer|min:1',
            'series'           => 'required|string|max:10',
            'currency_id'      => 'required|integer|min:1',
            'payment_method_id'=> 'required|integer|min:1',
            'customer_id'      => 'required|integer|min:1',
            'notes'            => 'nullable|string|max:500',
            'items'            => 'required|array|min:1',
            'items.*.product_id'  => 'nullable|integer|min:1',
            'items.*.description' => 'required|string|max:300',
            'items.*.quantity'    => 'required|numeric|min:0.001',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'items.*.unit_id'     => 'nullable|integer|min:1',
            'items.*.tax_type'    => 'nullable|string|max:20',
            'items.*.tax_rate'    => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $payload   = $validator->validated();
        $branchId  = (int) $payload['branch_id'];
        $warehouseId = isset($payload['warehouse_id']) ? (int) $payload['warehouse_id'] : null;

        $branchExists = DB::table('core.branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();

        if (!$branchExists) {
            return response()->json(['message' => 'Invalid branch scope'], 422);
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

    public function checkoutOrder(Request $request, $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $authUser->company_id;
        $orderId   = (int) $id;

        $validator = Validator::make($request->all(), [
            'target_document_kind' => 'required|string|in:INVOICE,RECEIPT',
            'series'               => 'nullable|string|max:10',
            'cash_register_id'     => 'nullable|integer|min:1',
            'payment_method_id'    => 'nullable|integer|min:1',
            'notes'                => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

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
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $status = strtoupper(trim((string) $request->query('status', '')));
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 20)));

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
            $branchExists = DB::table('core.branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

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

    public function updateComandaStatus(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:PENDING,IN_PREP,READY,SERVED,CANCELLED',
            'table_label' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

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
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->recipeService->getRecipe($companyId, $menuProductId);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
    }

    public function upsertRecipe(Request $request, int $menuProductId)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:300',
            'lines' => 'required|array|min:1',
            'lines.*.ingredient_product_id' => 'required|integer|min:1',
            'lines.*.qty_required_base' => 'required|numeric|min:0.00000001',
            'lines.*.wastage_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

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
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

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
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $status = strtoupper(trim((string) $request->query('status', '')));
        $search = trim((string) $request->query('search', ''));

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
            $branchExists = DB::table('core.branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

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

    public function createTable(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|integer|min:1',
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'capacity' => 'required|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $branchId = (int) $payload['branch_id'];

        $branchExists = DB::table('core.branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();

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

    public function updateTable(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->input('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:120',
            'capacity' => 'nullable|integer|min:1|max:30',
            'status' => 'nullable|string|in:AVAILABLE,OCCUPIED,RESERVED,DISABLED',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

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
        $normalized = array_values(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $codes
        )));

        if ($normalized === []) {
            return [];
        }

        return DB::table('sales.document_kinds')
            ->whereIn(DB::raw('UPPER(code)'), $normalized)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resolveDocumentKindAliasesByCodes(array $codes): array
    {
        $normalizedCodes = array_values(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $codes
        )));

        if ($normalizedCodes === []) {
            return [];
        }

        $aliases = $normalizedCodes;

        $rows = DB::table('sales.document_kinds')
            ->select('code', 'label')
            ->whereIn(DB::raw('UPPER(code)'), $normalizedCodes)
            ->get();

        foreach ($rows as $row) {
            $aliases[] = strtoupper(trim((string) ($row->code ?? '')));
            $aliases[] = strtoupper(trim((string) ($row->label ?? '')));
        }

        return array_values(array_unique(array_filter($aliases, static fn ($value) => $value !== '')));
    }
}
