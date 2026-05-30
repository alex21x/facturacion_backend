<?php

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Masters\GetMasterDataOptionsUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\CashRegisterRequest;
use App\Http\Requests\MasterData\DocumentKindRequest;
use App\Http\Requests\MasterData\DocumentKindsRequest;
use App\Http\Requests\MasterData\FunctionalProfileRequest;
use App\Http\Requests\MasterData\InventorySettingsRequest;
use App\Http\Requests\MasterData\LotRequest;
use App\Http\Requests\MasterData\PaymentMethodRequest;
use App\Http\Requests\MasterData\PosStationRequest;
use App\Http\Requests\MasterData\PriceTierRequest;
use App\Http\Requests\MasterData\RoleRequest;
use App\Http\Requests\MasterData\SeriesRequest;
use App\Http\Requests\MasterData\UnitsRequest;
use App\Http\Requests\MasterData\UserRequest;
use App\Http\Requests\MasterData\WarehouseRequest;
use App\Services\AppConfig\AdminSettingsMatrixService;
use App\Services\AppConfig\OperationalContextService;
use App\Services\AppConfig\OperationalLimitsService;
use App\Services\Inventory\InventoryControllerSupportService;
use App\Services\Sales\ReferenceDocumentService;
use App\Services\Sales\SalesLookupService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

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

    public function createFunctionalProfile(FunctionalProfileRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();
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

    public function updateFunctionalProfile(FunctionalProfileRequest $request, string $code)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

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

        $payload = $request->validated();
        $updates = ['updated_at' => now(), 'updated_by' => $authUser->id ?? null];

        foreach (['label', 'status', 'sort_order'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        $this->salesLookupService->updateCompanyFunctionalProfile($companyId, $normalizedCode, $updates, $authUser->id ?? null);

        return response()->json(['message' => 'Functional profile updated']);
    }

    public function createRole(RoleRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();
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

    public function updateRole(RoleRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $exists = $this->salesLookupService->roleExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $payload = $request->validated();
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

    public function createUser(UserRequest $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();
        try {
            $userId = $this->salesLookupService->createCompanyUser($companyId, $payload);
        } catch (QueryException $e) {
            return response()->json(['message' => 'No se pudo crear el usuario. Verifica datos y configuracion operacional.'], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'User created', 'id' => (int) $userId], 201);
    }

    public function updateUser(UserRequest $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $exists = $this->salesLookupService->companyUserExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $payload = $request->validated();

        try {
            $this->salesLookupService->updateCompanyUser($companyId, $id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'User updated']);
    }

    public function createWarehouse(WarehouseRequest $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $limits = $this->operationalLimitsService->getCompanyLimits($companyId);

        $payload = $request->validated();

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

    public function updateWarehouse(WarehouseRequest $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $exists = $this->salesLookupService->warehouseExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'Warehouse not found'], 404);
        }

        $payload = $request->validated();
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

    public function createCashRegister(CashRegisterRequest $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $limits = $this->resolveCompanyOperationalLimits($companyId);

        $payload = $request->validated();

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

    public function updateCashRegister(CashRegisterRequest $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $exists = $this->salesLookupService->cashRegisterExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'Cash register not found'], 404);
        }

        $payload = $request->validated();
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

    public function createPosStation(PosStationRequest $request)
    {
        $companyId = $this->resolveCompanyId($request);

        if (!$this->salesLookupService->posStationsTableExists()) {
            return response()->json(['message' => 'POS stations table not available'], 503);
        }

        $payload = $request->validated();
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

    public function updatePosStation(PosStationRequest $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        if (!$this->salesLookupService->posStationsTableExists()) {
            return response()->json(['message' => 'POS stations table not available'], 503);
        }

        $exists = $this->salesLookupService->posStationExists($companyId, $id);

        if (!$exists) {
            return response()->json(['message' => 'POS station not found'], 404);
        }

        $payload = $request->validated();
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

    public function createPaymentMethod(PaymentMethodRequest $request)
    {
        $payload = $request->validated();
        try {
            $id = $this->salesLookupService->createPaymentMethod($payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Payment method created', 'id' => (int) $id], 201);
    }

    public function updatePaymentMethod(PaymentMethodRequest $request, int $id)
    {
        $exists = $this->salesLookupService->paymentMethodExists($id);
        if (!$exists) {
            return response()->json(['message' => 'Payment method not found'], 404);
        }

        $payload = $request->validated();
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
            try {
                $this->salesLookupService->updatePaymentMethod($id, $updates);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
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

    public function createSeries(SeriesRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();
        try {
            $id = $this->salesLookupService->createSeries($companyId, (int) $authUser->id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Series created', 'id' => (int) $id], 201);
    }

    public function updateSeries(SeriesRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();
        try {
            $this->salesLookupService->updateSeries($companyId, (int) $authUser->id, $id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'not found') ? 404 : 422);
        }

        return response()->json(['message' => 'Series updated']);
    }

    public function createPriceTier(PriceTierRequest $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();
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

    public function updatePriceTier(PriceTierRequest $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();
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

    public function updateInventorySettings(InventorySettingsRequest $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $this->adminSettingsMatrixService->ensureInventorySettingsSchema();

        $payload = $request->validated();
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

    public function createLot(LotRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $payload = $request->validated();

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

    public function updateDocumentKinds(DocumentKindsRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $this->salesLookupService->ensureDocumentKindsTable();

        $items = $request->validated()['kinds'];

        try {
            $this->salesLookupService->updateDocumentKindsBulk($companyId, (int) $authUser->id, $items);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Document kinds updated']);
    }

    public function updateDocumentKind(DocumentKindRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $this->ensureDocumentKindsTable();

        $payload = $request->validated();
        try {
            $this->salesLookupService->updateDocumentKind($companyId, (int) $authUser->id, $id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'not found') ? 404 : 422);
        }

        return response()->json(['message' => 'Document kind updated']);
    }

    public function createDocumentKind(DocumentKindRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);
        $this->salesLookupService->ensureDocumentKindsTable();

        $payload = $request->validated();

        try {
            $this->salesLookupService->createDocumentKind($companyId, (int) $authUser->id, $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Document kind created'], 201);
    }

    public function updateUnits(UnitsRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $items = $request->validated()['units'];
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
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if ($companyId <= 0) {
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

    private function ensureDocumentKindsTable(): void
    {
        $this->salesLookupService->ensureDocumentKindsTable();
    }
}
