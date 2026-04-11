<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Restaurant\RestaurantComandaGateway;
use App\Services\Restaurant\RestaurantOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RestaurantController extends Controller
{
    public function __construct(
        private RestaurantComandaGateway $gateway,
        private RestaurantOrderService $orderService
    ) {
    }

    // =========================================================================
    // Restaurant order endpoints (vertical-specific, does NOT touch retail)
    // =========================================================================

    public function fetchOrders(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId  = $request->query('branch_id', $authUser->branch_id);
        $status    = strtoupper(trim((string) $request->query('status', '')));
        $search    = trim((string) $request->query('search', ''));
        $page      = max(1, (int) $request->query('page', 1));
        $perPage   = min(100, max(10, (int) $request->query('per_page', 20)));

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
                $companyId, $branchId, $status, $search, $page, $perPage
            );
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            return response()->json(['message' => $e->getMessage()], $code >= 400 && $code <= 599 ? $code : 500);
        }

        return response()->json($result);
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
}
