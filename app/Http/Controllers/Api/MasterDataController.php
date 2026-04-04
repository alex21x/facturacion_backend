<?php

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Masters\GetMasterDataOptionsUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MasterDataController extends Controller
{
    private const ROLE_FUNCTIONAL_PROFILES = ['SELLER', 'CASHIER', 'GENERAL'];

    private const DOCUMENT_KIND_CATALOG = [
        ['code' => 'QUOTATION', 'label' => 'Cotizacion'],
        ['code' => 'SALES_ORDER', 'label' => 'Pedido de Venta'],
        ['code' => 'INVOICE', 'label' => 'Factura'],
        ['code' => 'RECEIPT', 'label' => 'Boleta'],
        ['code' => 'CREDIT_NOTE', 'label' => 'Nota de Credito'],
        ['code' => 'DEBIT_NOTE', 'label' => 'Nota de Debito'],
    ];

    public function __construct(private GetMasterDataOptionsUseCase $getMasterDataOptionsUseCase)
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
        $units = $this->companyUnits($companyId);
        $options = $this->getMasterDataOptionsUseCase->execute($companyId);

        $warehouses = DB::table('inventory.warehouses')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'address', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $cashRegisters = DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $paymentMethods = DB::table('core.payment_methods')
            ->select('id', 'code', 'name', 'status')
            ->orderBy('name')
            ->get();

        $series = DB::table('sales.series_numbers')
            ->select([
                'id',
                'company_id',
                'branch_id',
                'warehouse_id',
                'document_kind',
                'series',
                'current_number',
                'number_padding',
                'reset_policy',
                'is_enabled',
            ])
            ->where('company_id', $companyId)
            ->orderBy('document_kind')
            ->orderBy('series')
            ->get();

        $priceTiers = DB::table('sales.price_tiers')
            ->select('id', 'company_id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
            ->where('company_id', $companyId)
            ->orderBy('priority')
            ->orderBy('min_qty')
            ->get();

        $lots = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'pl.warehouse_id')
            ->select([
                'pl.id',
                'pl.product_id',
                'p.name as product_name',
                'pl.warehouse_id',
                'w.name as warehouse_name',
                'pl.lot_code',
                'pl.manufacture_at',
                'pl.expires_at',
                'pl.unit_cost',
                'pl.status',
            ])
            ->where('pl.company_id', $companyId)
            ->orderByDesc('pl.received_at')
            ->limit(300)
            ->get();

        $inventorySettings = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$inventorySettings) {
            $inventorySettings = [
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
            // Cast boolean columns properly from database
            $inventorySettings = [
                'company_id' => $inventorySettings->company_id,
                'complexity_mode' => $inventorySettings->complexity_mode ?? 'BASIC',
                'inventory_mode' => $inventorySettings->inventory_mode ?? 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => $inventorySettings->lot_outflow_strategy ?? 'MANUAL',
                'enable_inventory_pro' => (bool) $inventorySettings->enable_inventory_pro,
                'enable_lot_tracking' => (bool) $inventorySettings->enable_lot_tracking,
                'enable_expiry_tracking' => (bool) $inventorySettings->enable_expiry_tracking,
                'enable_advanced_reporting' => (bool) $inventorySettings->enable_advanced_reporting,
                'enable_graphical_dashboard' => (bool) $inventorySettings->enable_graphical_dashboard,
                'enable_location_control' => (bool) $inventorySettings->enable_location_control,
                'allow_negative_stock' => (bool) $inventorySettings->allow_negative_stock,
                'enforce_lot_for_tracked' => (bool) $inventorySettings->enforce_lot_for_tracked,
            ];
        }

        $toggles = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', $this->documentKindFeatureCodes())
            ->pluck('is_enabled', 'feature_code');

        $documentKinds = collect(self::DOCUMENT_KIND_CATALOG)->map(function ($row) use ($toggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];

            return [
                'code' => $row['code'],
                'label' => $row['label'],
                'feature_code' => $featureCode,
                'is_enabled' => $toggles->has($featureCode) ? (bool) $toggles->get($featureCode) : true,
            ];
        })->values();

        return response()->json([
            'options' => $options,
            'warehouses' => $warehouses,
            'cash_registers' => $cashRegisters,
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
        $this->ensureCompanyRoleProfilesTable();

        $roleProfiles = DB::table('appcfg.company_role_profiles')
            ->where('company_id', $companyId)
            ->pluck('functional_profile', 'role_id');

        $modules = DB::table('appcfg.modules')
            ->select('id', 'code', 'name')
            ->where('status', 1)
            ->orderBy('name')
            ->get();

        $roles = DB::table('auth.roles')
            ->select('id', 'company_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get()
            ->map(function ($role) use ($modules, $roleProfiles) {
                $permissions = DB::table('auth.role_module_access as rma')
                    ->join('appcfg.modules as m', 'm.id', '=', 'rma.module_id')
                    ->where('rma.role_id', $role->id)
                    ->select([
                        'm.code as module_code',
                        'rma.can_view',
                        'rma.can_create',
                        'rma.can_update',
                        'rma.can_delete',
                        'rma.can_export',
                        'rma.can_approve',
                    ])
                    ->get()
                    ->keyBy('module_code');

                $modulePermissions = $modules->map(function ($module) use ($permissions) {
                    $current = $permissions->get($module->code);

                    return [
                        'module_code' => $module->code,
                        'can_view' => $current ? (bool) $current->can_view : false,
                        'can_create' => $current ? (bool) $current->can_create : false,
                        'can_update' => $current ? (bool) $current->can_update : false,
                        'can_delete' => $current ? (bool) $current->can_delete : false,
                        'can_export' => $current ? (bool) $current->can_export : false,
                        'can_approve' => $current ? (bool) $current->can_approve : false,
                    ];
                })->values();

                return [
                    'id' => (int) $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'status' => (int) $role->status,
                    'functional_profile' => $this->normalizeFunctionalProfile($roleProfiles->get($role->id) ?? null),
                    'permissions' => $modulePermissions,
                ];
            })
            ->values();

        $users = DB::table('auth.users as u')
            ->leftJoin('auth.user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('auth.roles as r', function ($join) use ($companyId) {
                $join->on('r.id', '=', 'ur.role_id')
                    ->where('r.company_id', '=', $companyId);
            })
            ->select([
                'u.id',
                'u.branch_id',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.phone',
                'u.status',
                DB::raw('MIN(r.id) as role_id'),
                DB::raw('MIN(r.code) as role_code'),
            ])
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->groupBy('u.id', 'u.branch_id', 'u.username', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.status')
            ->orderBy('u.username')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                    'username' => $row->username,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'status' => (int) $row->status,
                    'role_id' => $row->role_id !== null ? (int) $row->role_id : null,
                    'role_code' => $row->role_code,
                ];
            })
            ->values();

        return response()->json([
            'modules' => $modules,
            'roles' => $roles,
            'users' => $users,
        ]);
    }

    public function createRole(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'functional_profile' => 'nullable|string|in:SELLER,CASHIER,GENERAL',
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

        $roleId = DB::table('auth.roles')->insertGetId([
            'company_id' => $companyId,
            'code' => $code,
            'name' => trim($payload['name']),
            'status' => (int) ($payload['status'] ?? 1),
        ]);

        $this->syncRolePermissions((int) $roleId, $payload['permissions']);
        $this->syncRoleFunctionalProfile($companyId, (int) $roleId, $payload['functional_profile'] ?? null, $authUser->id ?? null);

        return response()->json(['message' => 'Role created', 'id' => (int) $roleId], 201);
    }

    public function updateRole(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'functional_profile' => 'nullable|string|in:SELLER,CASHIER,GENERAL',
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

        $exists = DB::table('auth.roles')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

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
            DB::table('auth.roles')->where('id', $id)->update($updates);
        }

        if (array_key_exists('permissions', $payload)) {
            $this->syncRolePermissions($id, $payload['permissions']);
        }

        if (array_key_exists('functional_profile', $payload)) {
            $this->syncRoleFunctionalProfile($companyId, $id, $payload['functional_profile'], $authUser->id ?? null);
        }

        return response()->json(['message' => 'Role updated']);
    }

    public function createUser(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
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

        if (!empty($payload['branch_id'])) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $roleExists = DB::table('auth.roles')
            ->where('id', (int) $payload['role_id'])
            ->where('company_id', $companyId)
            ->exists();

        if (!$roleExists) {
            return response()->json(['message' => 'Invalid role scope'], 422);
        }

        $userId = DB::table('auth.users')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'username' => trim($payload['username']),
            'password_hash' => Hash::make($payload['password']),
            'first_name' => trim($payload['first_name']),
            'last_name' => $payload['last_name'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('auth.user_roles')->insert([
            'user_id' => (int) $userId,
            'role_id' => (int) $payload['role_id'],
        ]);

        return response()->json(['message' => 'User created', 'id' => (int) $userId], 201);
    }

    public function updateUser(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
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

        $exists = DB::table('auth.users')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $payload = $validator->validated();

        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $updates = ['updated_at' => now()];

        foreach (['branch_id', 'first_name', 'last_name', 'email', 'phone', 'status'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        if (!empty($payload['password'])) {
            $updates['password_hash'] = Hash::make($payload['password']);
        }

        DB::table('auth.users')
            ->where('id', $id)
            ->update($updates);

        if (array_key_exists('role_id', $payload)) {
            $roleExists = DB::table('auth.roles')
                ->where('id', (int) $payload['role_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$roleExists) {
                return response()->json(['message' => 'Invalid role scope'], 422);
            }

            DB::table('auth.user_roles')->where('user_id', $id)->delete();
            DB::table('auth.user_roles')->insert([
                'user_id' => $id,
                'role_id' => (int) $payload['role_id'],
            ]);
        }

        return response()->json(['message' => 'User updated']);
    }

    public function units(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        return response()->json([
            'data' => $this->companyUnits($companyId),
        ]);
    }

    public function warehouses(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = DB::table('inventory.warehouses')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'address', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function createWarehouse(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

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
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $id = DB::table('inventory.warehouses')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'code' => strtoupper(trim($payload['code'])),
            'name' => trim($payload['name']),
            'address' => $payload['address'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
        ]);

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

        $exists = DB::table('inventory.warehouses')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Warehouse not found'], 404);
        }

        $payload = $validator->validated();
        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

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
        if (!empty($payload['name'])) {
            $updates['name'] = trim($payload['name']);
        }
        if (array_key_exists('address', $payload)) {
            $updates['address'] = $payload['address'];
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            DB::table('inventory.warehouses')->where('id', $id)->update($updates);
        }

        return response()->json(['message' => 'Warehouse updated']);
    }

    public function cashRegisters(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function createCashRegister(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        if (!empty($payload['branch_id'])) {
            $branchExists = DB::table('core.branches')
                ->where('id', (int) $payload['branch_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        $id = DB::table('sales.cash_registers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'code' => strtoupper(trim($payload['code'])),
            'name' => trim($payload['name']),
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Cash register created', 'id' => (int) $id], 201);
    }

    public function updateCashRegister(Request $request, int $id)
    {
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'code' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('sales.cash_registers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Cash register not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

        if (array_key_exists('branch_id', $payload)) {
            $updates['branch_id'] = $payload['branch_id'];
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
            DB::table('sales.cash_registers')->where('id', $id)->update($updates);
        }

        return response()->json(['message' => 'Cash register updated']);
    }

    public function paymentMethods()
    {
        $rows = DB::table('core.payment_methods')
            ->select('id', 'code', 'name', 'status')
            ->orderBy('name')
            ->get();

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

        $id = DB::table('core.payment_methods')->insertGetId([
            'code' => strtoupper(trim($payload['code'])),
            'name' => trim($payload['name']),
            'status' => (int) ($payload['status'] ?? 1),
        ]);

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

        $exists = DB::table('core.payment_methods')->where('id', $id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Payment method not found'], 404);
        }

        $payload = $validator->validated();
        $updates = [];

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
            DB::table('core.payment_methods')->where('id', $id)->update($updates);
        }

        return response()->json(['message' => 'Payment method updated']);
    }

    public function series(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = DB::table('sales.series_numbers')
            ->select([
                'id',
                'company_id',
                'branch_id',
                'warehouse_id',
                'document_kind',
                'series',
                'current_number',
                'number_padding',
                'reset_policy',
                'is_enabled',
            ])
            ->where('company_id', $companyId)
            ->orderBy('document_kind')
            ->orderBy('series')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function priceTiers(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $rows = DB::table('sales.price_tiers')
            ->select('id', 'company_id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
            ->where('company_id', $companyId)
            ->orderBy('priority')
            ->orderBy('min_qty')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function createSeries(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'document_kind' => 'required|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
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

        $id = DB::table('sales.series_numbers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'warehouse_id' => $payload['warehouse_id'] ?? null,
            'document_kind' => $payload['document_kind'],
            'series' => strtoupper(trim($payload['series'])),
            'current_number' => (int) ($payload['current_number'] ?? 0),
            'number_padding' => (int) ($payload['number_padding'] ?? 8),
            'reset_policy' => $payload['reset_policy'] ?? 'NONE',
            'is_enabled' => array_key_exists('is_enabled', $payload) ? (bool) $payload['is_enabled'] : true,
            'updated_by' => $authUser->id,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Series created', 'id' => (int) $id], 201);
    }

    public function updateSeries(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'document_kind' => 'nullable|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
            'series' => 'nullable|string|max:10',
            'current_number' => 'nullable|integer|min:0',
            'number_padding' => 'nullable|integer|min:4|max:12',
            'reset_policy' => 'nullable|string|in:NONE,YEARLY,MONTHLY',
            'is_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = DB::table('sales.series_numbers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Series not found'], 404);
        }

        $payload = $validator->validated();
        $updates = ['updated_by' => $authUser->id, 'updated_at' => now()];

        foreach (['branch_id', 'warehouse_id', 'document_kind', 'current_number', 'number_padding', 'reset_policy'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        if (!empty($payload['series'])) {
            $updates['series'] = strtoupper(trim($payload['series']));
        }

        if (array_key_exists('is_enabled', $payload)) {
            $updates['is_enabled'] = (bool) $payload['is_enabled'];
        }

        DB::table('sales.series_numbers')->where('id', $id)->update($updates);

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

        $code = strtoupper(trim($payload['code']));
        $existsCode = DB::table('sales.price_tiers')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->exists();

        if ($existsCode) {
            return response()->json(['message' => 'Price tier code already exists'], 422);
        }

        $id = DB::table('sales.price_tiers')->insertGetId([
            'company_id' => $companyId,
            'code' => $code,
            'name' => trim($payload['name']),
            'min_qty' => $minQty,
            'max_qty' => $maxQty,
            'priority' => (int) ($payload['priority'] ?? 1),
            'status' => (int) ($payload['status'] ?? 1),
        ]);

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

        $exists = DB::table('sales.price_tiers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Price tier not found'], 404);
        }

        $payload = $validator->validated();

        $current = DB::table('sales.price_tiers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        $minQty = array_key_exists('min_qty', $payload) ? (float) $payload['min_qty'] : (float) ($current->min_qty ?? 0);
        $maxQty = array_key_exists('max_qty', $payload)
            ? ($payload['max_qty'] !== null ? (float) $payload['max_qty'] : null)
            : ($current->max_qty !== null ? (float) $current->max_qty : null);

        if ($maxQty !== null && $maxQty < $minQty) {
            return response()->json(['message' => 'Max qty must be greater than or equal to min qty'], 422);
        }

        $updates = [];
        if (array_key_exists('code', $payload) && trim((string) $payload['code']) !== '') {
            $nextCode = strtoupper(trim((string) $payload['code']));
            $existsCode = DB::table('sales.price_tiers')
                ->where('company_id', $companyId)
                ->where('code', $nextCode)
                ->where('id', '!=', $id)
                ->exists();

            if ($existsCode) {
                return response()->json(['message' => 'Price tier code already exists'], 422);
            }

            $updates['code'] = $nextCode;
        }
        if (array_key_exists('name', $payload) && trim((string) $payload['name']) !== '') {
            $updates['name'] = trim((string) $payload['name']);
        }
        if (array_key_exists('min_qty', $payload)) {
            $updates['min_qty'] = (float) $payload['min_qty'];
        }
        if (array_key_exists('max_qty', $payload)) {
            $updates['max_qty'] = $payload['max_qty'] !== null ? (float) $payload['max_qty'] : null;
        }
        if (array_key_exists('priority', $payload)) {
            $updates['priority'] = (int) $payload['priority'];
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            DB::table('sales.price_tiers')->where('id', $id)->update($updates);
        }

        return response()->json(['message' => 'Price tier updated']);
    }

    public function inventorySettings(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $this->ensureInventorySettingsSchema();

        $row = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

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
            // Cast boolean columns properly from database
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
        $this->ensureInventorySettingsSchema();

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

        DB::table('inventory.inventory_settings')->updateOrInsert(
            ['company_id' => $companyId],
            $updates
        );

        return response()->json(['message' => 'Inventory settings updated']);
    }

    private function ensureInventorySettingsSchema(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.inventory_settings (company_id BIGINT PRIMARY KEY, inventory_mode VARCHAR(30) NOT NULL DEFAULT \'KARDEX_SIMPLE\', lot_outflow_strategy VARCHAR(20) NOT NULL DEFAULT \'MANUAL\', allow_negative_stock BOOLEAN NOT NULL DEFAULT FALSE, enforce_lot_for_tracked BOOLEAN NOT NULL DEFAULT FALSE, updated_at TIMESTAMPTZ NULL)');
        DB::statement("ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS complexity_mode VARCHAR(20) NOT NULL DEFAULT 'BASIC'");
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_inventory_pro BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_lot_tracking BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_expiry_tracking BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_advanced_reporting BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_graphical_dashboard BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE inventory.inventory_settings ADD COLUMN IF NOT EXISTS enable_location_control BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function lots(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);
        $productId = $request->query('product_id');
        $warehouseId = $request->query('warehouse_id');

        $query = DB::table('inventory.product_lots as pl')
            ->join('inventory.products as p', 'p.id', '=', 'pl.product_id')
            ->join('inventory.warehouses as w', 'w.id', '=', 'pl.warehouse_id')
            ->select([
                'pl.id',
                'pl.product_id',
                'p.name as product_name',
                'pl.warehouse_id',
                'w.name as warehouse_name',
                'pl.lot_code',
                'pl.manufacture_at',
                'pl.expires_at',
                'pl.unit_cost',
                'pl.status',
            ])
            ->where('pl.company_id', $companyId)
            ->orderByDesc('pl.received_at');

        if ($productId !== null && $productId !== '') {
            $query->where('pl.product_id', (int) $productId);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('pl.warehouse_id', (int) $warehouseId);
        }

        return response()->json(['data' => $query->limit(300)->get()]);
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

        $productExists = DB::table('inventory.products')
            ->where('id', (int) $payload['product_id'])
            ->where('company_id', $companyId)
            ->exists();

        $warehouseExists = DB::table('inventory.warehouses')
            ->where('id', (int) $payload['warehouse_id'])
            ->where('company_id', $companyId)
            ->exists();

        if (!$productExists || !$warehouseExists) {
            return response()->json(['message' => 'Invalid product or warehouse scope'], 422);
        }

        $id = DB::table('inventory.product_lots')->insertGetId([
            'company_id' => $companyId,
            'warehouse_id' => (int) $payload['warehouse_id'],
            'product_id' => (int) $payload['product_id'],
            'lot_code' => strtoupper(trim($payload['lot_code'])),
            'manufacture_at' => $payload['manufacture_at'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,
            'unit_cost' => $payload['unit_cost'] ?? null,
            'supplier_reference' => $payload['supplier_reference'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
            'created_by' => $authUser->id,
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Lot created', 'id' => (int) $id], 201);
    }

    public function documentKinds(Request $request)
    {
        $companyId = $this->resolveCompanyId($request);

        $toggles = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', $this->documentKindFeatureCodes())
            ->pluck('is_enabled', 'feature_code');

        $rows = collect(self::DOCUMENT_KIND_CATALOG)->map(function ($row) use ($toggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];

            return [
                'code' => $row['code'],
                'label' => $row['label'],
                'feature_code' => $featureCode,
                'is_enabled' => $toggles->has($featureCode) ? (bool) $toggles->get($featureCode) : true,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    public function updateDocumentKinds(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = $this->resolveCompanyId($request);

        $validator = Validator::make($request->all(), [
            'kinds' => 'required|array|min:1',
            'kinds.*.code' => 'required|string|in:QUOTATION,SALES_ORDER,INVOICE,RECEIPT,CREDIT_NOTE,DEBIT_NOTE',
            'kinds.*.is_enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $items = $validator->validated()['kinds'];

        foreach ($items as $item) {
            DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'feature_code' => 'DOC_KIND_' . $item['code'],
                ],
                [
                    'is_enabled' => (bool) $item['is_enabled'],
                    'config' => json_encode(['managed_by' => 'masters']),
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Document kinds updated']);
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

        $existingIds = DB::table('core.units')
            ->whereIn('id', $unitIds)
            ->pluck('id')
            ->map(function ($value) {
                return (int) $value;
            })
            ->values();

        if ($existingIds->count() !== $unitIds->count()) {
            return response()->json(['message' => 'One or more unit ids are invalid'], 422);
        }

        $this->ensureCompanyUnitsTable();

        foreach ($items as $item) {
            DB::table('appcfg.company_units')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'unit_id' => (int) $item['id'],
                ],
                [
                    'is_enabled' => (bool) $item['is_enabled'],
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Units updated']);
    }

    private function companyUnits(int $companyId)
    {
        $this->ensureCompanyUnitsTable();

        return DB::table('core.units as u')
            ->leftJoin('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select([
                'u.id',
                'u.code',
                'u.sunat_uom_code',
                'u.name',
                DB::raw('COALESCE(cu.is_enabled, false) as is_enabled'),
            ])
            ->orderBy('u.name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'code' => $row->code,
                    'sunat_uom_code' => $row->sunat_uom_code,
                    'name' => trim((string) $row->name),
                    'is_enabled' => (bool) $row->is_enabled,
                ];
            })
            ->values();
    }

    private function ensureCompanyUnitsTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_units (
                company_id BIGINT NOT NULL,
                unit_id BIGINT NOT NULL,
                is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, unit_id)
            )'
        );
    }

    private function ensureCompanyRoleProfilesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_role_profiles (
                company_id BIGINT NOT NULL,
                role_id BIGINT NOT NULL,
                functional_profile VARCHAR(20) NULL,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, role_id)
            )'
        );
    }

    private function syncRoleFunctionalProfile(int $companyId, int $roleId, ?string $functionalProfile, $updatedBy): void
    {
        $this->ensureCompanyRoleProfilesTable();

        $normalized = $this->normalizeFunctionalProfile($functionalProfile);

        DB::table('appcfg.company_role_profiles')->updateOrInsert(
            [
                'company_id' => $companyId,
                'role_id' => $roleId,
            ],
            [
                'functional_profile' => $normalized,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]
        );
    }

    private function normalizeFunctionalProfile($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, self::ROLE_FUNCTIONAL_PROFILES, true)) {
            return null;
        }

        return $normalized;
    }

    private function syncRolePermissions(int $roleId, array $permissions): void
    {
        $moduleCodeMap = DB::table('appcfg.modules')
            ->whereIn('code', collect($permissions)->pluck('module_code')->all())
            ->pluck('id', 'code');

        foreach ($permissions as $permission) {
            if (!$moduleCodeMap->has($permission['module_code'])) {
                continue;
            }

            $moduleId = (int) $moduleCodeMap->get($permission['module_code']);

            DB::table('auth.role_module_access')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'module_id' => $moduleId,
                ],
                [
                    'can_view' => (bool) $permission['can_view'],
                    'can_create' => (bool) $permission['can_create'],
                    'can_update' => (bool) $permission['can_update'],
                    'can_delete' => (bool) $permission['can_delete'],
                    'can_export' => (bool) $permission['can_export'],
                    'can_approve' => (bool) $permission['can_approve'],
                    'updated_at' => now(),
                ]
            );
        }
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

    private function documentKindFeatureCodes(): array
    {
        return collect(self::DOCUMENT_KIND_CATALOG)
            ->map(function ($row) {
                return 'DOC_KIND_' . $row['code'];
            })
            ->values()
            ->all();
    }
}
