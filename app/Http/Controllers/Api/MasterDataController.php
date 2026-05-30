<?php

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Masters\GetMasterDataOptionsUseCase;
use App\Http\Controllers\Controller;
use App\Services\AppConfig\AdminSettingsMatrixService;
use App\Services\AppConfig\OperationalContextService;
use App\Services\AppConfig\OperationalLimitsService;
use App\Services\Inventory\InventoryControllerSupportService;
use App\Services\Sales\ReferenceDocumentService;
use App\Services\Sales\SalesLookupService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MasterDataController extends Controller
{
    private const ROLE_FUNCTIONAL_PROFILES = [
        ['code' => 'GENERAL', 'label' => 'General', 'sort_order' => 10],
        ['code' => 'SELLER', 'label' => 'Vendedor', 'sort_order' => 20],
        ['code' => 'CASHIER', 'label' => 'Cajero', 'sort_order' => 30],
    ];

    public function __construct(
        private GetMasterDataOptionsUseCase $getMasterDataOptionsUseCase,
        private AdminSettingsMatrixService $adminSettingsMatrixService,
        private OperationalContextService $operationalContextService,
        private InventoryControllerSupportService $inventoryControllerSupportService,
        private OperationalLimitsService $operationalLimitsService,
        private SalesLookupService $salesLookupService,
        private ReferenceDocumentService $referenceDocumentService
    )
    {
    }

    public function options(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $options = $this->getMasterDataOptionsUseCase->execute($companyId);

        return response()->json([
            'branches' => $options['branches'],
            'warehouses' => $options['warehouses'],
            'products' => $options['products'],
        ]);
    }

    public function dashboard(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $this->salesLookupService->ensureDocumentKindsTable();
        $units = collect($this->inventoryControllerSupportService->listCompanyUnits($companyId));
        $options = $this->getMasterDataOptionsUseCase->execute($companyId);
        $dashboardData = $this->salesLookupService->buildDashboardData($companyId, $this->tableExists('appcfg', 'pos_stations'));
        $warehouses = $dashboardData['warehouses'];
        $cashRegisters = $dashboardData['cash_registers'];
        $stations = $dashboardData['pos_stations'];
        $paymentMethods = $dashboardData['payment_methods'];
        $series = $dashboardData['series'];
        $priceTiers = $dashboardData['price_tiers'];
        $lots = $dashboardData['lots'];
        $inventorySettings = $dashboardData['inventory_settings'];

        $documentKinds = $this->salesLookupService->listDocumentKindsForCompany($companyId);

        return response()->json([
            'options' => $options,
            'warehouses' => $warehouses,
            'cash_registers' => $cashRegisters,
            'pos_stations' => $stations,
            'payment_methods' => $paymentMethods,
            'series' => $series,
            'price_tiers' => $priceTiers,
            'lots' => $lots,
            'units' => $units,
            'inventory_settings' => $inventorySettings,
            'document_kinds' => $documentKinds,
            'stats' => [
                'warehouses_total' => $warehouses->count(),
                'cash_registers_total' => $cashRegisters->count(),
                'pos_stations_total' => $stations->count(),
                'payment_methods_total' => $paymentMethods->count(),
                'series_total' => $series->count(),
                'price_tiers_total' => $priceTiers->count(),
                'lots_total' => $lots->count(),
                'units_enabled_total' => $units->where('is_enabled', true)->count(),
            ],
        ]);
    }

    public function accessControl(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        return response()->json($this->salesLookupService->buildAccessControlData($companyId));
    }

    public function functionalProfiles(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        return response()->json([
            'functional_profiles' => $this->salesLookupService->listCompanyFunctionalProfiles($companyId),
        ]);
    }

    public function createFunctionalProfile(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
            'label' => 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $code = strtoupper(trim((string) $payload['code']));
        if ($code === '') {
            return response()->json(['message' => 'Functional profile code is required'], 422);
        }

        $exists = $this->salesLookupService->listCompanyFunctionalProfiles($companyId)
            ->contains(function ($row) use ($code) {
                return strtoupper(trim((string) ($row['code'] ?? ''))) === $code;
            });

        if ($exists) {
            return response()->json(['message' => 'Functional profile already exists'], 422);
        }

        $this->salesLookupService->createCompanyFunctionalProfile($companyId, (int) ($authUser->id ?? 0), [
            'code' => $code,
            'label' => trim((string) $payload['label']),
            'status' => (int) ($payload['status'] ?? 1),
            'sort_order' => (int) ($payload['sort_order'] ?? 100),
        ]);

        return response()->json(['message' => 'Functional profile created'], 201);
    }

    public function updateFunctionalProfile(Request $request, string $code)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'label' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $normalizedCode = strtoupper(trim((string) $code));
        if ($normalizedCode === '') {
            return response()->json(['message' => 'Functional profile not found'], 404);
        }

        $exists = $this->salesLookupService->listCompanyFunctionalProfiles($companyId)
            ->contains(function ($row) use ($normalizedCode) {
                return strtoupper(trim((string) ($row['code'] ?? ''))) === $normalizedCode;
            });

        if (!$exists) {
            return response()->json(['message' => 'Functional profile not found'], 404);
        }

        $payload = $validator->validated();
        $updates = ['updated_at' => now(), 'updated_by' => $authUser->id ?? null];

        foreach (['label', 'status', 'sort_order'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        $this->salesLookupService->updateCompanyFunctionalProfile($companyId, $normalizedCode, $updates, $authUser->id ?? null);

        return response()->json(['message' => 'Functional profile updated']);
    }

    public function createRole(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'functional_profile' => 'nullable|string|max:40',
            'permissions' => 'required|array|min:1',
            'permissions.*.module_code' => 'required|string|max:40',
            'permissions.*.can_view' => 'required|boolean',
            'permissions.*.can_create' => 'required|boolean',
            'permissions.*.can_update' => 'required|boolean',
            'permissions.*.can_delete' => 'required|boolean',
            'permissions.*.can_export' => 'required|boolean',
            'permissions.*.can_approve' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $code = strtoupper(trim($payload['code']));
        $functionalProfileCodes = $this->salesLookupService->functionalProfileCodes($companyId);
        $normalizedFunctionalProfile = $this->salesLookupService->normalizeFunctionalProfile($payload['functional_profile'] ?? null, $functionalProfileCodes);

        if (array_key_exists('functional_profile', $payload) && $payload['functional_profile'] !== null && $normalizedFunctionalProfile === null) {
            return response()->json(['message' => 'Invalid functional profile'], 422);
        }

        $roleId = $this->salesLookupService->createRole(
            $companyId,
            $code,
            trim($payload['name']),
            (int) ($payload['status'] ?? 1)
        );

        $this->salesLookupService->syncRolePermissions((int) $roleId, $payload['permissions']);
        $this->salesLookupService->syncRoleFunctionalProfile($companyId, (int) $roleId, $normalizedFunctionalProfile, $authUser->id ?? null);

        return response()->json(['message' => 'Role created', 'id' => (int) $roleId], 201);
    }

    public function updateRole(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'functional_profile' => 'nullable|string|max:40',
            'permissions' => 'nullable|array|min:1',
            'permissions.*.module_code' => 'required|string|max:40',
            'permissions.*.can_view' => 'required|boolean',
            'permissions.*.can_create' => 'required|boolean',
            'permissions.*.can_update' => 'required|boolean',
            'permissions.*.can_delete' => 'required|boolean',
            'permissions.*.can_export' => 'required|boolean',
            'permissions.*.can_approve' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = $this->salesLookupService->roleExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

        if (array_key_exists('name', $payload)) {
            $updates['name'] = trim($payload['name']);
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            $this->salesLookupService->updateRole($companyId, $id, $updates);
        }

        if (array_key_exists('permissions', $payload)) {
            $this->salesLookupService->syncRolePermissions($id, $payload['permissions']);
        }

        if (array_key_exists('functional_profile', $payload)) {
            $functionalProfileCodes = $this->salesLookupService->functionalProfileCodes($companyId);
            $normalizedFunctionalProfile = $this->salesLookupService->normalizeFunctionalProfile($payload['functional_profile'], $functionalProfileCodes);

            if ($payload['functional_profile'] !== null && $normalizedFunctionalProfile === null) {
                return response()->json(['message' => 'Invalid functional profile'], 422);
            }

            $this->salesLookupService->syncRoleFunctionalProfile($companyId, $id, $normalizedFunctionalProfile, $authUser->id ?? null);
        }

        return response()->json(['message' => 'Role updated']);
    }

    public function createUser(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'preferred_warehouse_id' => 'nullable|integer|min:1',
            'preferred_cash_register_id' => 'nullable|integer|min:1',
            'username' => 'required|string|max:80',
            'password' => 'required|string|min:6|max:120',
            'first_name' => 'required|string|max:80',
            'last_name' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:40',
            'status' => 'nullable|integer|in:0,1',
            'role_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        try {
            $userId = $this->salesLookupService->createCompanyUser($companyId, $payload);
        } catch (QueryException $e) {
            return response()->json(['message' => 'No se pudo crear el usuario. Verifica datos y configuracion operacional.'], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'User created', 'id' => (int) $userId], 201);
    }

    public function updateUser(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'preferred_warehouse_id' => 'nullable|integer|min:1',
            'preferred_cash_register_id' => 'nullable|integer|min:1',
            'password' => 'nullable|string|min:6|max:120',
            'first_name' => 'nullable|string|max:80',
            'last_name' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:40',
            'status' => 'nullable|integer|in:0,1',
            'role_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = $this->salesLookupService->companyUserExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $payload = $validator->validated();

        try {
            $this->salesLookupService->updateCompanyUser($companyId, $id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'User updated']);
    }

    public function createWarehouse(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $limits = $this->operationalLimitsService->resolveLimits($companyId);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:120',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        if (!empty($payload['branch_id'])) {
            $branchExists = $this->salesLookupService->branchExists($companyId, (int) $payload['branch_id']);

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $enabledWarehouses = $this->salesLookupService->countEnabledWarehouses($companyId);

        if ($enabledWarehouses >= $limits['max_warehouses_enabled']) {
            return response()->json([
                'message' => 'Se alcanzo el maximo de almacenes habilitados para esta empresa',
                'max_warehouses_enabled' => $limits['max_warehouses_enabled'],
            ], 422);
        }

        $id = $this->salesLookupService->createWarehouse($companyId, $payload);

        return response()->json(['message' => 'Warehouse created', 'id' => (int) $id], 201);
    }

    public function updateWarehouse(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:250',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = $this->salesLookupService->warehouseExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'Warehouse not found'], 404);
        }

        $payload = $validator->validated();
        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null) {
            $branchExists = $this->salesLookupService->branchExists($companyId, (int) $payload['branch_id']);

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $updates = [];
        if (array_key_exists('branch_id', $payload)) {
            $updates['branch_id'] = $payload['branch_id'];
        }
        if (!empty($payload['code'])) {
            $updates['code'] = strtoupper(trim($payload['code']));
        }
        if (array_key_exists('name', $payload)) {
            $updates['name'] = trim((string) $payload['name']);
        }
        if (array_key_exists('address', $payload)) {
            $updates['address'] = $payload['address'];
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            $this->salesLookupService->updateWarehouse($companyId, $id, $updates);
        }

        return response()->json(['message' => 'Warehouse updated']);
    }

    public function cashRegisters(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = $this->salesLookupService->listCashRegistersForCompany($companyId);

        return response()->json(['data' => $rows]);
    }

    public function posStations(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        if (!$this->salesLookupService->posStationsTableExists()) {
            return response()->json(['data' => []]);
        }

        $rows = $this->salesLookupService->listPosStationsForCompany($companyId);

        return response()->json(['data' => $rows]);
    }

    public function createCashRegister(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $limits = $this->resolveCompanyOperationalLimits($companyId);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        if (!empty($payload['branch_id'])) {
            $branchExists = $this->salesLookupService->branchExists($companyId, (int) $payload['branch_id']);

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        if (!empty($payload['warehouse_id'])) {
            $warehouseExists = $this->salesLookupService->activeWarehouseExists($companyId, (int) $payload['warehouse_id']);

            if (!$warehouseExists) {
                return response()->json(['message' => 'Invalid warehouse scope'], 422);
            }
        }

        $enabledCashRegisters = $this->salesLookupService->countEnabledCashRegisters($companyId);

        if ($enabledCashRegisters >= $limits['max_cash_registers_enabled']) {
            return response()->json([
                'message' => 'Se alcanzo el maximo de cajas habilitadas para esta empresa',
                'max_cash_registers_enabled' => $limits['max_cash_registers_enabled'],
            ], 422);
        }

        $warehouseId = array_key_exists('warehouse_id', $payload) && $payload['warehouse_id'] !== null
            ? (int) $payload['warehouse_id']
            : null;

        if ($warehouseId !== null) {
            $enabledCashRegistersForWarehouse = $this->salesLookupService->countEnabledCashRegistersForWarehouse($companyId, $warehouseId);

            if ($enabledCashRegistersForWarehouse >= $limits['max_cash_registers_per_warehouse']) {
                return response()->json([
                    'message' => 'Se alcanzo el maximo de cajas por almacen definido para esta empresa',
                    'max_cash_registers_per_warehouse' => $limits['max_cash_registers_per_warehouse'],
                ], 422);
            }
        } else {
            return response()->json([
                'message' => 'Debe seleccionar un almacen para registrar la caja y aplicar el limite por almacen',
            ], 422);
        }

        $id = $this->salesLookupService->createCashRegister($companyId, $payload, $warehouseId);

        return response()->json(['message' => 'Cash register created', 'id' => (int) $id], 201);
    }

    public function updateCashRegister(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'code' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = $this->salesLookupService->cashRegisterExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'Cash register not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

        if (array_key_exists('warehouse_id', $payload) && $payload['warehouse_id'] !== null) {
            $warehouseExists = $this->salesLookupService->activeWarehouseExists($companyId, (int) $payload['warehouse_id']);

            if (!$warehouseExists) {
                return response()->json(['message' => 'Invalid warehouse scope'], 422);
            }
        }

        if (array_key_exists('branch_id', $payload)) {
            $updates['branch_id'] = $payload['branch_id'];
        }
        if (array_key_exists('warehouse_id', $payload)) {
            $updates['warehouse_id'] = $payload['warehouse_id'];
        }
        if (!empty($payload['code'])) {
            $updates['code'] = strtoupper(trim($payload['code']));
        }
        if (!empty($payload['name'])) {
            $updates['name'] = trim($payload['name']);
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            $this->salesLookupService->updateCashRegister($companyId, $id, $updates);
        }

        return response()->json(['message' => 'Cash register updated']);
    }

    public function createPosStation(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        if (!$this->salesLookupService->posStationsTableExists()) {
            return response()->json(['message' => 'POS stations table not available'], 503);
        }

        $validator = Validator::make($request->all(), [
            'cash_register_id' => 'required|integer|min:1',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:120',
            'device_id' => ['required', 'string', 'max:120', 'regex:/^CAJA-\d{3}$/i'],
            'device_name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ], [
            'device_id.regex' => 'Device ID debe tener el formato CAJA-001.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $cashRegisterId = (int) $payload['cash_register_id'];
        $normalizedCode = strtoupper(trim($payload['code']));
        $normalizedDeviceId = strtoupper(trim((string) $payload['device_id']));

        if ($normalizedDeviceId === '') {
            return response()->json(['message' => 'Device ID is required'], 422);
        }

        $cashRegisterExists = $this->salesLookupService->activeCashRegisterExists($companyId, $cashRegisterId);

        if (!$cashRegisterExists) {
            return response()->json(['message' => 'Invalid cash register scope'], 422);
        }

        $codeExists = $this->salesLookupService->posStationCodeExists($companyId, $normalizedCode);

        if ($codeExists) {
            return response()->json(['message' => 'Station code already exists for this company'], 422);
        }

        $deviceConflict = $this->salesLookupService->findPosStationDeviceConflict($companyId, $normalizedDeviceId);

        if ($deviceConflict) {
            return response()->json([
                'message' => 'Device already assigned to another station',
                'conflict' => [
                    'station_id' => (int) $deviceConflict->id,
                    'station_code' => (string) $deviceConflict->code,
                    'company_id' => (int) $deviceConflict->company_id,
                ],
            ], 422);
        }

        $id = $this->salesLookupService->createPosStation($companyId, $cashRegisterId, $payload, $normalizedCode, $normalizedDeviceId);

        return response()->json(['message' => 'POS station created', 'id' => (int) $id], 201);
    }

    public function updatePosStation(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        if (!$this->salesLookupService->posStationsTableExists()) {
            return response()->json(['message' => 'POS stations table not available'], 503);
        }

        $validator = Validator::make($request->all(), [
            'cash_register_id' => 'nullable|integer|min:1',
            'code' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:120',
            'device_id' => ['nullable', 'string', 'max:120', 'regex:/^CAJA-\d{3}$/i'],
            'device_name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ], [
            'device_id.regex' => 'Device ID debe tener el formato CAJA-001.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = $this->salesLookupService->posStationExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'POS station not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

        if (array_key_exists('cash_register_id', $payload) && $payload['cash_register_id'] !== null) {
            $cashRegisterExists = $this->salesLookupService->activeCashRegisterExists($companyId, (int) $payload['cash_register_id']);

            if (!$cashRegisterExists) {
                return response()->json(['message' => 'Invalid cash register scope'], 422);
            }

            $updates['cash_register_id'] = (int) $payload['cash_register_id'];
        }

        if (!empty($payload['code'])) {
            $normalizedCode = strtoupper(trim($payload['code']));
            $codeExists = $this->salesLookupService->posStationCodeExists($companyId, $normalizedCode, $id);

            if ($codeExists) {
                return response()->json(['message' => 'Station code already exists for this company'], 422);
            }

            $updates['code'] = $normalizedCode;
        }

        if (!empty($payload['name'])) {
            $updates['name'] = trim($payload['name']);
        }

        if (!empty($payload['device_id'])) {
            $normalizedDeviceId = strtoupper(trim((string) $payload['device_id']));
            if ($normalizedDeviceId === '') {
                return response()->json(['message' => 'Device ID is required'], 422);
            }

            $deviceConflict = $this->salesLookupService->findPosStationDeviceConflict($companyId, $normalizedDeviceId, $id);

            if ($deviceConflict) {
                return response()->json([
                    'message' => 'Device already assigned to another station',
                    'conflict' => [
                        'station_id' => (int) $deviceConflict->id,
                        'station_code' => (string) $deviceConflict->code,
                        'company_id' => (int) $deviceConflict->company_id,
                    ],
                ], 422);
            }

            $updates['device_id'] = $normalizedDeviceId;
        }

        if (array_key_exists('device_name', $payload)) {
            $updates['device_name'] = !empty($payload['device_name']) ? trim($payload['device_name']) : null;
        }

        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            $updates['updated_at'] = now();
            $this->salesLookupService->updatePosStation($companyId, $id, $updates);
        }

        return response()->json(['message' => 'POS station updated']);
    }

    public function paymentMethods()
    {
        $rows = $this->salesLookupService->listPaymentMethodsForMasterData();

        return response()->json(['data' => $rows]);
    }

    public function createPaymentMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $id = $this->salesLookupService->createPaymentMethod($payload);

        return response()->json(['message' => 'Payment method created', 'id' => (int) $id], 201);
    }

    public function updatePaymentMethod(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'nullable|string|max:20',
            'name' => 'nullable|string|max:100',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = $this->salesLookupService->paymentMethodExists($id);
        if (!$exists) {
            return response()->json(['message' => 'Payment method not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

        if (!empty($payload['code'])) {
            $updates['comment'] = strtoupper(trim($payload['code']));
        }
        if (!empty($payload['name'])) {
            $updates['name'] = trim($payload['name']);
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
            $updates['is_active'] = ((int) $payload['status']) === 1 ? 1 : 0;
        }

        if (!empty($updates)) {
            $this->salesLookupService->updatePaymentMethod($id, $updates);
        }

        return response()->json(['message' => 'Payment method updated']);
    }

    public function series(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = $this->salesLookupService->listSeriesNumbers($companyId, null, null, false, null, null);

        return response()->json(['data' => $rows]);
    }

    public function priceTiers(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = $this->salesLookupService->listPriceTiersForCompany($companyId);

        return response()->json(['data' => $rows]);
    }

    public function createSeries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $documentKindRule = 'required|string|in:' . implode(',', $this->documentKindCodes());

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'document_kind' => $documentKindRule,
            'series' => 'required|string|max:10',
            'current_number' => 'nullable|integer|min:0',
            'number_padding' => 'nullable|integer|min:4|max:12',
            'reset_policy' => 'nullable|string|in:NONE,YEARLY,MONTHLY',
            'is_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        try {
            $id = $this->salesLookupService->createSeries($companyId, (int) $authUser->id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Series created', 'id' => (int) $id], 201);
    }

    public function updateSeries(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $documentKindRule = 'nullable|string|in:' . implode(',', $this->documentKindCodes());

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'document_kind' => $documentKindRule,
            'series' => 'nullable|string|max:10',
            'current_number' => 'nullable|integer|min:0',
            'number_padding' => 'nullable|integer|min:4|max:12',
            'reset_policy' => 'nullable|string|in:NONE,YEARLY,MONTHLY',
            'is_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        try {
            $this->salesLookupService->updateSeries($companyId, (int) $authUser->id, $id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'not found') ? 404 : 422);
        }

        return response()->json(['message' => 'Series updated']);
    }

    public function createPriceTier(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:120',
            'min_qty' => 'required|numeric|gt:0',
            'max_qty' => 'nullable|numeric|gt:0',
            'priority' => 'nullable|integer|min:1',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $minQty = (float) $payload['min_qty'];
        $maxQty = array_key_exists('max_qty', $payload) && $payload['max_qty'] !== null ? (float) $payload['max_qty'] : null;

        if ($maxQty !== null && $maxQty < $minQty) {
            return response()->json(['message' => 'Max qty must be greater than or equal to min qty'], 422);
        }

        try {
            $id = $this->referenceDocumentService->createPriceTier($companyId, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Price tier created', 'id' => (int) $id], 201);
    }

    public function updatePriceTier(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'code' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:120',
            'min_qty' => 'nullable|numeric|gt:0',
            'max_qty' => 'nullable|numeric|gt:0',
            'priority' => 'nullable|integer|min:1',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        try {
            $this->referenceDocumentService->updatePriceTier($companyId, $id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'not found') ? 404 : 422);
        }

        return response()->json(['message' => 'Price tier updated']);
    }

    public function inventorySettings(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $this->adminSettingsMatrixService->ensureInventorySettingsSchema();
        $row = $this->adminSettingsMatrixService->getInventorySettingsByCompany()->get($companyId);

        if (!$row) {
            $row = [
                'company_id' => $companyId,
                'complexity_mode' => 'BASIC',
                'inventory_mode' => 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => 'MANUAL',
                'enable_inventory_pro' => false,
                'enable_lot_tracking' => false,
                'enable_expiry_tracking' => false,
                'enable_advanced_reporting' => false,
                'enable_graphical_dashboard' => false,
                'enable_location_control' => false,
                'allow_negative_stock' => false,
                'enforce_lot_for_tracked' => false,
            ];
        } else {
            $row = [
                'company_id' => $row->company_id,
                'complexity_mode' => $row->complexity_mode ?? 'BASIC',
                'inventory_mode' => $row->inventory_mode ?? 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => $row->lot_outflow_strategy ?? 'MANUAL',
                'enable_inventory_pro' => (bool) $row->enable_inventory_pro,
                'enable_lot_tracking' => (bool) $row->enable_lot_tracking,
                'enable_expiry_tracking' => (bool) $row->enable_expiry_tracking,
                'enable_advanced_reporting' => (bool) $row->enable_advanced_reporting,
                'enable_graphical_dashboard' => (bool) $row->enable_graphical_dashboard,
                'enable_location_control' => (bool) $row->enable_location_control,
                'allow_negative_stock' => (bool) $row->allow_negative_stock,
                'enforce_lot_for_tracked' => (bool) $row->enforce_lot_for_tracked,
            ];
        }

        return response()->json(['data' => $row]);
    }

    public function updateInventorySettings(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $this->adminSettingsMatrixService->ensureInventorySettingsSchema();

        $validator = Validator::make($request->all(), [
            'complexity_mode' => 'nullable|string|in:BASIC,ADVANCED',
            'inventory_mode' => 'nullable|string|in:KARDEX_SIMPLE,LOT_TRACKING',
            'lot_outflow_strategy' => 'nullable|string|in:MANUAL,FIFO,FEFO',
            'enable_inventory_pro' => 'nullable|boolean',
            'enable_lot_tracking' => 'nullable|boolean',
            'enable_expiry_tracking' => 'nullable|boolean',
            'enable_advanced_reporting' => 'nullable|boolean',
            'enable_graphical_dashboard' => 'nullable|boolean',
            'enable_location_control' => 'nullable|boolean',
            'allow_negative_stock' => 'nullable|boolean',
            'enforce_lot_for_tracked' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $updates = ['updated_at' => now()];

        foreach ([
            'complexity_mode',
            'inventory_mode',
            'lot_outflow_strategy',
            'enable_inventory_pro',
            'enable_lot_tracking',
            'enable_expiry_tracking',
            'enable_advanced_reporting',
            'enable_graphical_dashboard',
            'enable_location_control',
            'allow_negative_stock',
            'enforce_lot_for_tracked'
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        $complexityMode = (string) ($payload['complexity_mode'] ?? '');
        if ($complexityMode === 'BASIC') {
            $updates['inventory_mode'] = 'KARDEX_SIMPLE';
            $updates['lot_outflow_strategy'] = 'MANUAL';
            $updates['enable_inventory_pro'] = false;
            $updates['enable_lot_tracking'] = false;
            $updates['enable_expiry_tracking'] = false;
            $updates['enable_advanced_reporting'] = false;
            $updates['enable_graphical_dashboard'] = false;
            $updates['enable_location_control'] = false;
            $updates['enforce_lot_for_tracked'] = false;
        }

        if (($updates['enable_lot_tracking'] ?? null) === false) {
            $updates['enforce_lot_for_tracked'] = false;
            if (!array_key_exists('inventory_mode', $updates)) {
                $updates['inventory_mode'] = 'KARDEX_SIMPLE';
            }
        }

        if (($updates['enable_expiry_tracking'] ?? null) === true
            && (($updates['enable_lot_tracking'] ?? ($payload['enable_lot_tracking'] ?? null)) === false)) {
            return response()->json(['message' => 'Expiry tracking requires lot tracking'], 422);
        }

        $this->adminSettingsMatrixService->upsertInventorySettings($companyId, $updates);

        return response()->json(['message' => 'Inventory settings updated']);
    }

    public function lots(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $productId = $request->query('product_id');
        $warehouseId = $request->query('warehouse_id');

        $resolvedProductId = ($productId !== null && $productId !== '') ? (int) $productId : null;
        $resolvedWarehouseId = ($warehouseId !== null && $warehouseId !== '') ? (int) $warehouseId : null;

        return response()->json([
            'data' => $this->salesLookupService->listLotsForCompany($companyId, $resolvedProductId, $resolvedWarehouseId, 300),
        ]);
    }

    public function createLot(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'lot_code' => 'required|string|max:60',
            'manufacture_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier_reference' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $productExists = $this->salesLookupService->companyProductExists($companyId, (int) $payload['product_id']);
        $warehouseExists = $this->salesLookupService->companyWarehouseExists($companyId, (int) $payload['warehouse_id']);

        if (!$productExists || !$warehouseExists) {
            return response()->json(['message' => 'Invalid product or warehouse scope'], 422);
        }

        $id = $this->salesLookupService->createLot($companyId, (int) $authUser->id, $payload);

        return response()->json(['message' => 'Lot created', 'id' => (int) $id], 201);
    }

    public function documentKinds(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        return response()->json(['data' => $this->salesLookupService->listDocumentKindsForCompany($companyId)]);
    }

    public function updateDocumentKinds(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $this->salesLookupService->ensureDocumentKindsTable();

        $validator = Validator::make($request->all(), [
            'kinds' => 'required|array|min:1',
            'kinds.*.original_code' => 'nullable|string|max:30',
            'kinds.*.code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/'],
            'kinds.*.label' => 'nullable|string|max:120',
            'kinds.*.is_enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $items = $validator->validated()['kinds'];

        try {
            $this->salesLookupService->updateDocumentKindsBulk($companyId, (int) $authUser->id, $items);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Document kinds updated']);
    }

    public function updateDocumentKind(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $this->ensureDocumentKindsTable();

        $validator = Validator::make($request->all(), [
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/'],
            'label' => 'nullable|string|max:120',
            'is_enabled' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        try {
            $this->salesLookupService->updateDocumentKind($companyId, (int) $authUser->id, $id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'not found') ? 404 : 422);
        }

        return response()->json(['message' => 'Document kind updated']);
    }

    public function createDocumentKind(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $this->salesLookupService->ensureDocumentKindsTable();

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/'],
            'label' => 'required|string|max:120',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        try {
            $this->salesLookupService->createDocumentKind($companyId, (int) $authUser->id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Document kind created'], 201);
    }

    public function updateUnits(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'units' => 'required|array|min:1',
            'units.*.id' => 'required|integer|min:1',
            'units.*.is_enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $items = $validator->validated()['units'];
        $unitIds = collect($items)->pluck('id')->map(function ($value) {
            return (int) $value;
        })->unique()->values();

        $existingIds = collect($this->inventoryControllerSupportService->existingUnitIds($unitIds->all()));

        if ($existingIds->count() !== $unitIds->count()) {
            return response()->json(['message' => 'One or more unit ids are invalid'], 422);
        }

        $this->inventoryControllerSupportService->updateCompanyUnits($companyId, $items, (int) $authUser->id);

        return response()->json(['message' => 'Units updated']);
    }

    private function resolveCompanyOperationalLimits(int $companyId): array
    {
        return $this->operationalLimitsService->getCompanyLimits($companyId);
    }

    private function resolveCompanyId(Request $request): int
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            throw new HttpResponseException(response()->json(['message' => 'Invalid company scope'], 403));
        }

        return $companyId;
    }

    private function tableExists(string $schema, string $table): bool
    {
        return $this->salesLookupService->tableExists($schema . '.' . $table);
    }

    private function tableColumnExists(string $schema, string $table, string $column): bool
    {
        return in_array($column, $this->salesLookupService->tableColumns($schema . '.' . $table), true);
    }

    private function documentKindFeatureCodes(): array
    {
        return $this->documentKindCatalog()
            ->map(function ($row) {
                return 'DOC_KIND_' . (string) $row['code'];
            })
            ->values()
            ->all();
    }

    private function documentKindCodes(): array
    {
        return $this->documentKindCatalog()
            ->map(function ($row) {
                return (string) $row['code'];
            })
            ->values()
            ->all();
    }

    private function documentKindCatalog()
    {
        return collect($this->salesLookupService->listDocumentKindsCatalog());
    }

    private function ensureDocumentKindsTable(): void
    {
        $this->salesLookupService->ensureDocumentKindsTable();
    }

    private function resolveDocumentKindIdByCode(string $code): ?int
    {
        return $this->salesLookupService->resolveDocumentKindIdByCode($code);
    }
}
