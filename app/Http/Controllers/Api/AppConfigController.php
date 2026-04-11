<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AppConfigController extends Controller
{
    private array $activeVerticalCache = [];
    private array $verticalFeaturePreferenceCache = [];

    private const COMMERCE_FEATURE_CODES = [
        'PRODUCT_MULTI_UOM',
        'PRODUCT_UOM_CONVERSIONS',
        'PRODUCT_WHOLESALE_PRICING',
        'INVENTORY_PRODUCTS_BY_PROFILE',
        'INVENTORY_PRODUCT_MASTERS_BY_PROFILE',
        'SALES_CUSTOMER_PRICE_PROFILE',
        'SALES_SELLER_TO_CASHIER',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
        'SALES_ANTICIPO_ENABLED',
        'SALES_TAX_BRIDGE',
        'SALES_DETRACCION_ENABLED',
        'SALES_RETENCION_ENABLED',
        'SALES_PERCEPCION_ENABLED',
        'PURCHASES_DETRACCION_ENABLED',
        'PURCHASES_RETENCION_COMPRADOR_ENABLED',
        'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
        'PURCHASES_PERCEPCION_ENABLED',
    ];

    public function operationalContext(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);
        $warehouseId = $request->query('warehouse_id');
        $cashRegisterId = $request->query('cash_register_id');

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $resolvedBranchId = null;
        if ($branchId !== null && $branchId !== '') {
            $resolvedBranchId = (int) $branchId;
        }

        $resolvedWarehouseId = null;
        if ($warehouseId !== null && $warehouseId !== '') {
            $resolvedWarehouseId = (int) $warehouseId;
        }

        $resolvedCashRegisterId = null;
        if ($cashRegisterId !== null && $cashRegisterId !== '') {
            $resolvedCashRegisterId = (int) $cashRegisterId;
        }

        $company = DB::table('core.companies')
            ->select('id', 'tax_id', 'legal_name', 'trade_name', 'status')
            ->where('id', $companyId)
            ->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $branches = DB::table('core.branches')
            ->select('id', 'company_id', 'code', 'name', 'is_main', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $warehouses = DB::table('inventory.warehouses')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($resolvedBranchId !== null, function ($query) use ($resolvedBranchId) {
                $query->where(function ($nested) use ($resolvedBranchId) {
                    $nested->where('branch_id', $resolvedBranchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->orderBy('name')
            ->get();

        $cashRegisters = DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'warehouse_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($resolvedBranchId !== null, function ($query) use ($resolvedBranchId) {
                $query->where(function ($nested) use ($resolvedBranchId) {
                    $nested->where('branch_id', $resolvedBranchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->when($resolvedWarehouseId !== null, function ($query) use ($resolvedWarehouseId) {
                $query->where(function ($nested) use ($resolvedWarehouseId) {
                    $nested->where('warehouse_id', $resolvedWarehouseId)
                        ->orWhereNull('warehouse_id');
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'company' => $company,
            'active_vertical' => $this->resolveActiveCompanyVertical($companyId),
            'branches' => $branches,
            'warehouses' => $warehouses,
            'cash_registers' => $cashRegisters,
            'selected' => [
                'company_id' => $companyId,
                'branch_id' => $resolvedBranchId,
                'warehouse_id' => $resolvedWarehouseId,
                'cash_register_id' => $resolvedCashRegisterId,
            ],
            'limits' => [
                'platform' => $this->fetchPlatformLimits(),
                'company' => $this->fetchCompanyOperationalLimits($companyId),
                'usage' => $this->fetchCompanyOperationalUsage($companyId),
            ],
        ]);
    }

    public function modules(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ($branchId !== null) {
            $branchId = (int) $branchId;
        }

        $rows = DB::table('appcfg.modules as m')
            ->leftJoin('appcfg.company_modules as cm', function ($join) use ($companyId) {
                $join->on('cm.module_id', '=', 'm.id')
                    ->where('cm.company_id', '=', $companyId);
            })
            ->leftJoin('appcfg.branch_modules as bm', function ($join) use ($companyId, $branchId) {
                $join->on('bm.module_id', '=', 'm.id')
                    ->where('bm.company_id', '=', $companyId);

                if ($branchId !== null) {
                    $join->where('bm.branch_id', '=', $branchId);
                } else {
                    $join->whereRaw('1 = 0');
                }
            })
            ->select([
                'm.id',
                'm.code',
                'm.name',
                'm.description',
                'm.is_core',
                'm.status',
                DB::raw('cm.is_enabled as company_enabled'),
                DB::raw('bm.is_enabled as branch_enabled'),
                DB::raw("CASE WHEN bm.is_enabled IS NOT NULL THEN bm.is_enabled WHEN cm.is_enabled IS NOT NULL THEN cm.is_enabled ELSE m.is_core END as is_enabled"),
            ])
            ->orderBy('m.name')
            ->get();

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'modules' => $rows,
        ]);
    }

    public function featureToggles(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ($branchId !== null) {
            $branchId = (int) $branchId;
        }

        $companyFeatures = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('feature_code');

        $branchFeatures = collect();
        if ($branchId !== null) {
            $branchFeatures = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->get()
                ->keyBy('feature_code');
        }

        $featureCodes = $companyFeatures->keys()->merge($branchFeatures->keys())->unique()->values();

        $features = $featureCodes->map(function ($featureCode) use ($companyId, $companyFeatures, $branchFeatures) {
            $company = $companyFeatures->get($featureCode);
            $branch = $branchFeatures->get($featureCode);

            $isEnabled = false;
            if ($branch && $branch->is_enabled !== null) {
                $isEnabled = (bool) $branch->is_enabled;
            } elseif ($company && $company->is_enabled !== null) {
                $isEnabled = (bool) $company->is_enabled;
            }

            $companyConfig = $company ? $this->decodeJsonConfig($company->config) : null;
            $branchConfig = $branch ? $this->decodeJsonConfig($branch->config) : null;

            $verticalPreference = $this->resolveVerticalFeaturePreference($companyId, (string) $featureCode);
            if ($verticalPreference['resolved']) {
                if ($verticalPreference['is_enabled'] !== null) {
                    $isEnabled = (bool) $verticalPreference['is_enabled'];
                }
                if ($verticalPreference['config'] !== null) {
                    $companyConfig = $verticalPreference['config'];
                    $branchConfig = null;
                }
            }

            return [
                'feature_code' => $featureCode,
                'is_enabled' => $isEnabled,
                'company_enabled' => $company ? (bool) $company->is_enabled : null,
                'branch_enabled' => $branch ? (bool) $branch->is_enabled : null,
                'company_config' => $companyConfig,
                'branch_config' => $branchConfig,
                'vertical_source' => $verticalPreference['source'],
            ];
        })->values();

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'features' => $features,
        ]);
    }

    public function companyVerticalSettings(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if (!$this->tableExists('appcfg', 'verticals') || !$this->tableExists('appcfg', 'company_verticals')) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $verticals = DB::table('appcfg.verticals as v')
            ->leftJoin('appcfg.company_verticals as cv', function ($join) use ($companyId) {
                $join->on('cv.vertical_id', '=', 'v.id')
                    ->where('cv.company_id', '=', $companyId)
                    ->where('cv.status', '=', 1);
            })
            ->select([
                'v.id',
                'v.code',
                'v.name',
                'v.description',
                'v.status',
                DB::raw('CASE WHEN cv.id IS NULL THEN false ELSE true END as is_assigned'),
                DB::raw('CASE WHEN cv.is_primary IS NULL THEN false ELSE cv.is_primary END as is_primary'),
                'cv.effective_from',
                'cv.effective_to',
            ])
            ->where('v.status', 1)
            ->orderBy('v.name')
            ->get();

        $active = $verticals->first(function ($row) {
            return (bool) $row->is_primary === true;
        });

        return response()->json([
            'company_id' => $companyId,
            'active_vertical' => $active ? [
                'id' => (int) $active->id,
                'code' => (string) $active->code,
                'name' => (string) $active->name,
                'description' => $active->description,
                'effective_from' => $active->effective_from,
                'effective_to' => $active->effective_to,
            ] : null,
            'verticals' => $verticals,
        ]);
    }

    public function updateCompanyVerticalSettings(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$this->tableExists('appcfg', 'verticals') || !$this->tableExists('appcfg', 'company_verticals')) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'vertical_code' => 'required|string|max:50',
            'effective_from' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);
        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $verticalCode = strtoupper(trim((string) ($payload['vertical_code'] ?? '')));
        $vertical = DB::table('appcfg.verticals')
            ->whereRaw('UPPER(code) = ?', [$verticalCode])
            ->where('status', 1)
            ->first(['id', 'code', 'name']);

        if (!$vertical) {
            return response()->json([
                'message' => 'Invalid vertical code',
            ], 422);
        }

        $effectiveFrom = (string) ($payload['effective_from'] ?? now()->toDateString());

        DB::transaction(function () use ($companyId, $vertical, $effectiveFrom, $authUser) {
            DB::table('appcfg.company_verticals')
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->update([
                    'is_primary' => false,
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]);

            DB::table('appcfg.company_verticals')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'vertical_id' => (int) $vertical->id,
                ],
                [
                    'is_primary' => true,
                    'status' => 1,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                    'created_by' => $authUser->id,
                    'created_at' => now(),
                ]
            );
        });

        return $this->companyVerticalSettings($request);
    }

    public function companyVerticalAdminMatrix(Request $request)
    {
        if (!$this->tableExists('appcfg', 'verticals') || !$this->tableExists('appcfg', 'company_verticals')) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $verticals = DB::table('appcfg.verticals')
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description']);

        $companies = DB::table('core.companies')
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);

        $this->ensureCompanyAccessLinksForCompanies($companies);
        $accessLinksByCompany = collect();
        if ($this->tableExists('appcfg', 'company_access_links')) {
            $accessLinksByCompany = DB::table('appcfg.company_access_links')
                ->whereIn('company_id', $companies->pluck('id')->all())
                ->get(['company_id', 'access_slug', 'is_active'])
                ->keyBy('company_id');
        }

        $assignments = DB::table('appcfg.company_verticals as cv')
            ->join('appcfg.verticals as v', 'v.id', '=', 'cv.vertical_id')
            ->where('v.status', 1)
            ->orderBy('cv.company_id')
            ->orderBy('v.name')
            ->get([
                'cv.company_id',
                'cv.vertical_id',
                'v.code as vertical_code',
                'v.name as vertical_name',
                'cv.status',
                'cv.is_primary',
                'cv.effective_from',
                'cv.effective_to',
            ]);

        $byCompany = [];
        foreach ($assignments as $row) {
            $companyId = (int) $row->company_id;
            if (!array_key_exists($companyId, $byCompany)) {
                $byCompany[$companyId] = [];
            }

            $byCompany[$companyId][] = [
                'vertical_id' => (int) $row->vertical_id,
                'vertical_code' => (string) $row->vertical_code,
                'vertical_name' => (string) $row->vertical_name,
                'is_enabled' => (int) $row->status === 1,
                'is_primary' => (bool) $row->is_primary,
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to,
            ];
        }

        $adminUsersByCompany = collect();
        $adminUsersRaw = DB::table('auth.users as u')
            ->join('auth.user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('u.status', 1)
            ->whereRaw("UPPER(r.code) = 'ADMIN'")
            ->whereIn('u.company_id', $companies->pluck('id')->all())
            ->orderBy('u.id')
            ->get(['u.id', 'u.company_id', 'u.username', 'u.email']);
        foreach ($adminUsersRaw as $au) {
            $cid = (int) $au->company_id;
            if (!$adminUsersByCompany->has($cid)) {
                $adminUsersByCompany->put($cid, $au);
            }
        }

        $companyRows = $companies->map(function ($company) use ($byCompany, $accessLinksByCompany, $adminUsersByCompany) {
            $companyId = (int) $company->id;
            $companyAssignments = $byCompany[$companyId] ?? [];
            $accessLink = $accessLinksByCompany->get($companyId);
            $accessSlug = $accessLink ? (string) $accessLink->access_slug : null;
            $adminUser = $adminUsersByCompany->get($companyId);

            $active = null;
            foreach ($companyAssignments as $assignment) {
                if ($assignment['is_enabled'] && $assignment['is_primary']) {
                    $active = $assignment;
                    break;
                }
            }

            return [
                'company_id' => $companyId,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'active_vertical_code' => $active['vertical_code'] ?? null,
                'active_vertical_name' => $active['vertical_name'] ?? null,
                'access_slug' => $accessSlug,
                'access_url' => $accessSlug ? $this->buildCompanyAccessUrl($accessSlug) : null,
                'access_link_active' => $accessLink ? ((int) $accessLink->is_active === 1) : false,
                'assignments' => $companyAssignments,
                'admin_username' => $adminUser ? $adminUser->username : null,
                'admin_email' => $adminUser ? $adminUser->email : null,
            ];
        })->values();

        return response()->json([
            'verticals' => $verticals,
            'companies' => $companyRows,
        ]);
    }

    public function updateCompanyVerticalAdminMatrix(Request $request)
    {
        if (!$this->tableExists('appcfg', 'verticals') || !$this->tableExists('appcfg', 'company_verticals')) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|min:1',
            'vertical_code' => 'required|string|max:50',
            'is_enabled' => 'required|boolean',
            'make_primary' => 'nullable|boolean',
            'effective_from' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) $payload['company_id'];
        $verticalCode = strtoupper(trim((string) $payload['vertical_code']));
        $isEnabled = (bool) $payload['is_enabled'];
        $makePrimary = array_key_exists('make_primary', $payload) ? (bool) $payload['make_primary'] : true;
        $effectiveFrom = (string) ($payload['effective_from'] ?? now()->toDateString());

        $companyExists = DB::table('core.companies')->where('id', $companyId)->exists();
        if (!$companyExists) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $vertical = DB::table('appcfg.verticals')
            ->whereRaw('UPPER(code) = ?', [$verticalCode])
            ->where('status', 1)
            ->first(['id', 'code', 'name']);

        if (!$vertical) {
            return response()->json([
                'message' => 'Invalid vertical code',
            ], 422);
        }

        DB::transaction(function () use ($companyId, $vertical, $isEnabled, $makePrimary, $effectiveFrom, $authUser) {
            if ($isEnabled) {
                DB::table('core.companies')
                    ->where('id', $companyId)
                    ->update([
                        'status' => 1,
                        'updated_at' => now(),
                    ]);

                if ($makePrimary) {
                    DB::table('appcfg.company_verticals')
                        ->where('company_id', $companyId)
                        ->where('status', 1)
                        ->update([
                            'is_primary' => false,
                            'updated_by' => $authUser->id,
                            'updated_at' => now(),
                        ]);
                }

                DB::table('appcfg.company_verticals')->updateOrInsert(
                    [
                        'company_id' => $companyId,
                        'vertical_id' => (int) $vertical->id,
                    ],
                    [
                        'status' => 1,
                        'is_primary' => $makePrimary,
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                        'created_by' => $authUser->id,
                        'created_at' => now(),
                    ]
                );

                $hasPrimary = DB::table('appcfg.company_verticals')
                    ->where('company_id', $companyId)
                    ->where('status', 1)
                    ->where('is_primary', true)
                    ->exists();

                if (!$hasPrimary) {
                    $firstEnabled = DB::table('appcfg.company_verticals')
                        ->where('company_id', $companyId)
                        ->where('status', 1)
                        ->orderBy('updated_at', 'desc')
                        ->first(['id']);

                    if ($firstEnabled) {
                        DB::table('appcfg.company_verticals')
                            ->where('id', (int) $firstEnabled->id)
                            ->update([
                                'is_primary' => true,
                                'updated_by' => $authUser->id,
                                'updated_at' => now(),
                            ]);
                    }
                }

                return;
            }

            DB::table('core.companies')
                ->where('id', $companyId)
                ->update([
                    'status' => 0,
                    'updated_at' => now(),
                ]);

            DB::table('appcfg.company_verticals')
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->update([
                    'status' => 0,
                    'is_primary' => false,
                    'effective_to' => now()->toDateString(),
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]);

            $companyUserIds = DB::table('auth.users')
                ->where('company_id', $companyId)
                ->pluck('id');

            if ($companyUserIds->isNotEmpty()) {
                DB::table('auth.refresh_tokens')
                    ->whereIn('user_id', $companyUserIds->all())
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                    ]);
            }

            $hasPrimary = DB::table('appcfg.company_verticals')
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->where('is_primary', true)
                ->exists();

            if (!$hasPrimary) {
                $fallback = DB::table('appcfg.company_verticals')
                    ->where('company_id', $companyId)
                    ->where('status', 1)
                    ->orderBy('updated_at', 'desc')
                    ->first(['id']);

                if ($fallback) {
                    DB::table('appcfg.company_verticals')
                        ->where('id', (int) $fallback->id)
                        ->update([
                            'is_primary' => true,
                            'updated_by' => $authUser->id,
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        return $this->companyVerticalAdminMatrix($request);
    }

    public function updateCompanyVerticalAdminMatrixBulk(Request $request)
    {
        if (!$this->tableExists('appcfg', 'verticals') || !$this->tableExists('appcfg', 'company_verticals')) {
            return response()->json([
                'message' => 'Verticalization tables not found. Execute migration 2026_04_07_000301 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'required|integer|min:1',
            'vertical_code' => 'required|string|max:50',
            'is_enabled' => 'required|boolean',
            'make_primary' => 'nullable|boolean',
            'effective_from' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyIds = collect($payload['company_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $verticalCode = strtoupper(trim((string) $payload['vertical_code']));
        $isEnabled = (bool) $payload['is_enabled'];
        $makePrimary = array_key_exists('make_primary', $payload) ? (bool) $payload['make_primary'] : true;
        $effectiveFrom = (string) ($payload['effective_from'] ?? now()->toDateString());

        if (empty($companyIds)) {
            return response()->json([
                'message' => 'company_ids is required',
            ], 422);
        }

        $existingCompanies = DB::table('core.companies')
            ->whereIn('id', $companyIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_values(array_diff($companyIds, $existingCompanies));
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Some companies were not found',
                'missing_company_ids' => $missing,
            ], 404);
        }

        $vertical = DB::table('appcfg.verticals')
            ->whereRaw('UPPER(code) = ?', [$verticalCode])
            ->where('status', 1)
            ->first(['id', 'code', 'name']);

        if (!$vertical) {
            return response()->json([
                'message' => 'Invalid vertical code',
            ], 422);
        }

        foreach ($companyIds as $companyId) {
            DB::transaction(function () use ($companyId, $vertical, $isEnabled, $makePrimary, $effectiveFrom, $authUser) {
                if ($isEnabled) {
                    DB::table('core.companies')
                        ->where('id', $companyId)
                        ->update([
                            'status' => 1,
                            'updated_at' => now(),
                        ]);

                    if ($makePrimary) {
                        DB::table('appcfg.company_verticals')
                            ->where('company_id', $companyId)
                            ->where('status', 1)
                            ->update([
                                'is_primary' => false,
                                'updated_by' => $authUser->id,
                                'updated_at' => now(),
                            ]);
                    }

                    DB::table('appcfg.company_verticals')->updateOrInsert(
                        [
                            'company_id' => $companyId,
                            'vertical_id' => (int) $vertical->id,
                        ],
                        [
                            'status' => 1,
                            'is_primary' => $makePrimary,
                            'effective_from' => $effectiveFrom,
                            'effective_to' => null,
                            'updated_by' => $authUser->id,
                            'updated_at' => now(),
                            'created_by' => $authUser->id,
                            'created_at' => now(),
                        ]
                    );

                    $hasPrimary = DB::table('appcfg.company_verticals')
                        ->where('company_id', $companyId)
                        ->where('status', 1)
                        ->where('is_primary', true)
                        ->exists();

                    if (!$hasPrimary) {
                        $firstEnabled = DB::table('appcfg.company_verticals')
                            ->where('company_id', $companyId)
                            ->where('status', 1)
                            ->orderBy('updated_at', 'desc')
                            ->first(['id']);

                        if ($firstEnabled) {
                            DB::table('appcfg.company_verticals')
                                ->where('id', (int) $firstEnabled->id)
                                ->update([
                                    'is_primary' => true,
                                    'updated_by' => $authUser->id,
                                    'updated_at' => now(),
                                ]);
                        }
                    }

                    return;
                }

                DB::table('core.companies')
                    ->where('id', $companyId)
                    ->update([
                        'status' => 0,
                        'updated_at' => now(),
                    ]);

                DB::table('appcfg.company_verticals')
                    ->where('company_id', $companyId)
                    ->where('status', 1)
                    ->update([
                        'status' => 0,
                        'is_primary' => false,
                        'effective_to' => now()->toDateString(),
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]);

                $companyUserIds = DB::table('auth.users')
                    ->where('company_id', $companyId)
                    ->pluck('id');

                if ($companyUserIds->isNotEmpty()) {
                    DB::table('auth.refresh_tokens')
                        ->whereIn('user_id', $companyUserIds->all())
                        ->whereNull('revoked_at')
                        ->update([
                            'revoked_at' => now(),
                        ]);
                }

                $hasPrimary = DB::table('appcfg.company_verticals')
                    ->where('company_id', $companyId)
                    ->where('status', 1)
                    ->where('is_primary', true)
                    ->exists();

                if (!$hasPrimary) {
                    $fallback = DB::table('appcfg.company_verticals')
                        ->where('company_id', $companyId)
                        ->where('status', 1)
                        ->orderBy('updated_at', 'desc')
                        ->first(['id']);

                    if ($fallback) {
                        DB::table('appcfg.company_verticals')
                            ->where('id', (int) $fallback->id)
                            ->update([
                                'is_primary' => true,
                                'updated_by' => $authUser->id,
                                'updated_at' => now(),
                            ]);
                    }
                }
            });
        }

        return $this->companyVerticalAdminMatrix($request);
    }

    public function companyRateLimitMatrix(Request $request)
    {
        $defaultRead = (int) env('DEFAULT_COMPANY_RATE_LIMIT_PER_MINUTE', 3600);
        $defaultWrite = (int) env('DEFAULT_COMPANY_RATE_LIMIT_WRITE_PER_MINUTE', 2400);
        $defaultReports = (int) env('DEFAULT_COMPANY_RATE_LIMIT_REPORTS_PER_MINUTE', 900);

        $companies = DB::table('core.companies')
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);

        $limitsByCompany = collect();
        if ($this->tableExists('appcfg', 'company_rate_limits')) {
            $limitsByCompany = DB::table('appcfg.company_rate_limits')
                ->get([
                    'company_id',
                    'is_enabled',
                    'requests_per_minute',
                    'requests_per_minute_read',
                    'requests_per_minute_write',
                    'requests_per_minute_reports',
                    'plan_code',
                    'last_preset_code',
                    'updated_at',
                ])
                ->keyBy('company_id');
        }

        $rows = $companies->map(function ($company) use ($limitsByCompany, $defaultRead, $defaultWrite, $defaultReports) {
            $limit = $limitsByCompany->get((int) $company->id);

            return [
                'company_id' => (int) $company->id,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'is_enabled' => $limit ? ((int) ($limit->is_enabled ?? 1) === 1) : true,
                'requests_per_minute' => $limit ? (int) ($limit->requests_per_minute ?? $defaultRead) : $defaultRead,
                'requests_per_minute_read' => $limit ? (int) ($limit->requests_per_minute_read ?? $limit->requests_per_minute ?? $defaultRead) : $defaultRead,
                'requests_per_minute_write' => $limit ? (int) ($limit->requests_per_minute_write ?? $limit->requests_per_minute ?? $defaultWrite) : $defaultWrite,
                'requests_per_minute_reports' => $limit ? (int) ($limit->requests_per_minute_reports ?? $limit->requests_per_minute ?? $defaultReports) : $defaultReports,
                'plan_code' => $limit ? (string) ($limit->plan_code ?? 'PRO') : 'PRO',
                'last_preset_code' => $limit ? ($limit->last_preset_code ? (string) $limit->last_preset_code : null) : null,
                'updated_at' => $limit->updated_at ?? null,
            ];
        })->values();

        return response()->json([
            'defaults' => [
                'requests_per_minute_read' => $defaultRead,
                'requests_per_minute_write' => $defaultWrite,
                'requests_per_minute_reports' => $defaultReports,
            ],
            'presets' => $this->companyRateLimitPresets($defaultRead, $defaultWrite, $defaultReports),
            'companies' => $rows,
        ]);
    }

    public function updateCompanyRateLimitMatrix(Request $request)
    {
        if (!$this->tableExists('appcfg', 'company_rate_limits')) {
            return response()->json([
                'message' => 'Rate limit table not found. Execute migration 2026_04_08_000402 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|min:1',
            'is_enabled' => 'required|boolean',
            'requests_per_minute_read' => 'required|integer|min:100|max:60000',
            'requests_per_minute_write' => 'required|integer|min:100|max:60000',
            'requests_per_minute_reports' => 'required|integer|min:100|max:60000',
            'plan_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE,CUSTOM',
            'preset_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) $payload['company_id'];

        $companyExists = DB::table('core.companies')->where('id', $companyId)->exists();
        if (!$companyExists) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        DB::table('appcfg.company_rate_limits')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'is_enabled' => (bool) $payload['is_enabled'],
                'requests_per_minute' => (int) $payload['requests_per_minute_read'],
                'requests_per_minute_read' => (int) $payload['requests_per_minute_read'],
                'requests_per_minute_write' => (int) $payload['requests_per_minute_write'],
                'requests_per_minute_reports' => (int) $payload['requests_per_minute_reports'],
                'plan_code' => (string) ($payload['plan_code'] ?? 'CUSTOM'),
                'last_preset_code' => $payload['preset_code'] ?? null,
                'updated_by' => $authUser ? $authUser->id : null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->logCompanyRateLimitAudit(
            $companyId,
            'SINGLE',
            (string) ($payload['plan_code'] ?? 'CUSTOM'),
            isset($payload['preset_code']) ? (string) $payload['preset_code'] : null,
            (bool) $payload['is_enabled'],
            (int) $payload['requests_per_minute_read'],
            (int) $payload['requests_per_minute_write'],
            (int) $payload['requests_per_minute_reports'],
            $authUser ? (int) $authUser->id : null
        );

        return $this->companyRateLimitMatrix($request);
    }

    public function updateCompanyRateLimitMatrixBulk(Request $request)
    {
        if (!$this->tableExists('appcfg', 'company_rate_limits')) {
            return response()->json([
                'message' => 'Rate limit table not found. Execute migration 2026_04_08_000402 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'required|integer|min:1',
            'is_enabled' => 'required|boolean',
            'requests_per_minute_read' => 'required|integer|min:100|max:60000',
            'requests_per_minute_write' => 'required|integer|min:100|max:60000',
            'requests_per_minute_reports' => 'required|integer|min:100|max:60000',
            'plan_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE,CUSTOM',
            'preset_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyIds = collect($payload['company_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        $existingCompanies = DB::table('core.companies')
            ->whereIn('id', $companyIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_values(array_diff($companyIds, $existingCompanies));
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Some companies were not found',
                'missing_company_ids' => $missing,
            ], 404);
        }

        foreach ($companyIds as $companyId) {
            DB::table('appcfg.company_rate_limits')->updateOrInsert(
                ['company_id' => $companyId],
                [
                    'is_enabled' => (bool) $payload['is_enabled'],
                    'requests_per_minute' => (int) $payload['requests_per_minute_read'],
                    'requests_per_minute_read' => (int) $payload['requests_per_minute_read'],
                    'requests_per_minute_write' => (int) $payload['requests_per_minute_write'],
                    'requests_per_minute_reports' => (int) $payload['requests_per_minute_reports'],
                    'plan_code' => (string) ($payload['plan_code'] ?? 'CUSTOM'),
                    'last_preset_code' => $payload['preset_code'] ?? null,
                    'updated_by' => $authUser ? $authUser->id : null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $this->logCompanyRateLimitAudit(
                $companyId,
                'BULK',
                (string) ($payload['plan_code'] ?? 'CUSTOM'),
                isset($payload['preset_code']) ? (string) $payload['preset_code'] : null,
                (bool) $payload['is_enabled'],
                (int) $payload['requests_per_minute_read'],
                (int) $payload['requests_per_minute_write'],
                (int) $payload['requests_per_minute_reports'],
                $authUser ? (int) $authUser->id : null
            );
        }

        return $this->companyRateLimitMatrix($request);
    }

    public function companyOperationalLimitMatrix(Request $request)
    {
        $companies = DB::table('core.companies')
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);

        $limitsByCompany = collect();
        if ($this->tableExists('appcfg', 'company_operational_limits')) {
            $limitsByCompany = DB::table('appcfg.company_operational_limits')
                ->get([
                    'company_id',
                    'max_branches_enabled',
                    'max_warehouses_enabled',
                    'max_cash_registers_enabled',
                    'max_cash_registers_per_warehouse',
                    'updated_at',
                ])
                ->keyBy('company_id');
        }

        $rows = $companies->map(function ($company) use ($limitsByCompany) {
            $companyId = (int) $company->id;
            $limits = $limitsByCompany->get($companyId);

            $usageBranches = (int) DB::table('core.branches')->where('company_id', $companyId)->where('status', 1)->count();
            $usageWarehouses = (int) DB::table('inventory.warehouses')->where('company_id', $companyId)->where('status', 1)->count();
            $usageCashRegisters = (int) DB::table('sales.cash_registers')->where('company_id', $companyId)->where('status', 1)->count();

            return [
                'company_id' => $companyId,
                'tax_id' => $company->tax_id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'company_status' => (int) $company->status,
                'max_branches_enabled' => max(1, (int) ($limits->max_branches_enabled ?? 1)),
                'max_warehouses_enabled' => max(1, (int) ($limits->max_warehouses_enabled ?? 1)),
                'max_cash_registers_enabled' => max(1, (int) ($limits->max_cash_registers_enabled ?? 1)),
                'max_cash_registers_per_warehouse' => max(1, (int) ($limits->max_cash_registers_per_warehouse ?? 1)),
                'usage_branches' => $usageBranches,
                'usage_warehouses' => $usageWarehouses,
                'usage_cash_registers' => $usageCashRegisters,
                'updated_at' => $limits->updated_at ?? null,
            ];
        })->values();

        return response()->json([
            'defaults' => [
                'max_branches_enabled' => 1,
                'max_warehouses_enabled' => 1,
                'max_cash_registers_enabled' => 1,
                'max_cash_registers_per_warehouse' => 1,
            ],
            'companies' => $rows,
        ]);
    }

    public function updateCompanyOperationalLimitMatrix(Request $request)
    {
        if (!$this->tableExists('appcfg', 'company_operational_limits')) {
            return response()->json([
                'message' => 'Operational limits table not found. Execute migration 2026_04_08_000405 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|min:1',
            'max_branches_enabled' => 'required|integer|min:1|max:10000',
            'max_warehouses_enabled' => 'required|integer|min:1|max:10000',
            'max_cash_registers_enabled' => 'required|integer|min:1|max:10000',
            'max_cash_registers_per_warehouse' => 'required|integer|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) $payload['company_id'];
        $companyExists = DB::table('core.companies')->where('id', $companyId)->exists();
        if (!$companyExists) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        DB::table('appcfg.company_operational_limits')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'max_branches_enabled' => (int) $payload['max_branches_enabled'],
                'max_warehouses_enabled' => (int) $payload['max_warehouses_enabled'],
                'max_cash_registers_enabled' => (int) $payload['max_cash_registers_enabled'],
                'max_cash_registers_per_warehouse' => (int) $payload['max_cash_registers_per_warehouse'],
                'updated_by' => $authUser ? $authUser->id : null,
                'updated_at' => now(),
            ]
        );

        return $this->companyOperationalLimitMatrix($request);
    }

    public function updateCompanyOperationalLimitMatrixBulk(Request $request)
    {
        if (!$this->tableExists('appcfg', 'company_operational_limits')) {
            return response()->json([
                'message' => 'Operational limits table not found. Execute migration 2026_04_08_000405 first.',
            ], 409);
        }

        $authUser = $request->attributes->get('auth_user');
        $validator = Validator::make($request->all(), [
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'required|integer|min:1',
            'max_branches_enabled' => 'required|integer|min:1|max:10000',
            'max_warehouses_enabled' => 'required|integer|min:1|max:10000',
            'max_cash_registers_enabled' => 'required|integer|min:1|max:10000',
            'max_cash_registers_per_warehouse' => 'required|integer|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyIds = collect($payload['company_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($companyIds as $companyId) {
            DB::table('appcfg.company_operational_limits')->updateOrInsert(
                ['company_id' => $companyId],
                [
                    'max_branches_enabled' => (int) $payload['max_branches_enabled'],
                    'max_warehouses_enabled' => (int) $payload['max_warehouses_enabled'],
                    'max_cash_registers_enabled' => (int) $payload['max_cash_registers_enabled'],
                    'max_cash_registers_per_warehouse' => (int) $payload['max_cash_registers_per_warehouse'],
                    'updated_by' => $authUser ? $authUser->id : null,
                    'updated_at' => now(),
                ]
            );
        }

        return $this->companyOperationalLimitMatrix($request);
    }

    public function createAdminCompany(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'tax_id' => 'required|string|min:8|max:20',
            'legal_name' => 'required|string|min:3|max:200',
            'trade_name' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:60',
            'address' => 'nullable|string|max:500',
            'vertical_code' => 'nullable|string|max:50',
            'main_branch_code' => 'nullable|string|max:20',
            'main_branch_name' => 'nullable|string|max:120',
            'create_default_warehouse' => 'nullable|boolean',
            'default_warehouse_code' => 'nullable|string|max:20',
            'default_warehouse_name' => 'nullable|string|max:120',
            'create_default_cash_register' => 'nullable|boolean',
            'default_cash_register_code' => 'nullable|string|max:20',
            'default_cash_register_name' => 'nullable|string|max:120',
            'admin_username' => 'required|string|min:4|max:80',
            'admin_password' => 'required|string|min:8|max:120',
            'admin_first_name' => 'required|string|min:2|max:80',
            'admin_last_name' => 'nullable|string|max:80',
            'admin_email' => 'nullable|email|max:120',
            'admin_phone' => 'nullable|string|max:40',
            'plan_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE,CUSTOM',
            'preset_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE',
            'requests_per_minute_read' => 'nullable|integer|min:100|max:60000',
            'requests_per_minute_write' => 'nullable|integer|min:100|max:60000',
            'requests_per_minute_reports' => 'nullable|integer|min:100|max:60000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $taxId = trim((string) $payload['tax_id']);
        $adminUsername = trim((string) $payload['admin_username']);

        $taxIdExists = DB::table('core.companies')
            ->whereRaw('UPPER(tax_id) = ?', [strtoupper($taxId)])
            ->exists();
        if ($taxIdExists) {
            return response()->json([
                'message' => 'Ya existe una empresa con ese RUC',
            ], 422);
        }

        $usernameExists = DB::table('auth.users')
            ->whereRaw('UPPER(username) = ?', [strtoupper($adminUsername)])
            ->exists();
        if ($usernameExists) {
            return response()->json([
                'message' => 'El usuario administrador ya existe',
            ], 422);
        }

        $defaultRead = (int) env('DEFAULT_COMPANY_RATE_LIMIT_PER_MINUTE', 3600);
        $defaultWrite = (int) env('DEFAULT_COMPANY_RATE_LIMIT_WRITE_PER_MINUTE', 2400);
        $defaultReports = (int) env('DEFAULT_COMPANY_RATE_LIMIT_REPORTS_PER_MINUTE', 900);

        $planCode = (string) ($payload['plan_code'] ?? 'PRO');
        $presetCode = isset($payload['preset_code']) ? (string) $payload['preset_code'] : null;

        $readRate = (int) ($payload['requests_per_minute_read'] ?? $defaultRead);
        $writeRate = (int) ($payload['requests_per_minute_write'] ?? $defaultWrite);
        $reportsRate = (int) ($payload['requests_per_minute_reports'] ?? $defaultReports);

        if ($presetCode !== null) {
            $presets = collect($this->companyRateLimitPresets($defaultRead, $defaultWrite, $defaultReports))->keyBy('code');
            if ($presets->has($presetCode)) {
                $preset = $presets->get($presetCode);
                $readRate = (int) ($preset['requests_per_minute_read'] ?? $readRate);
                $writeRate = (int) ($preset['requests_per_minute_write'] ?? $writeRate);
                $reportsRate = (int) ($preset['requests_per_minute_reports'] ?? $reportsRate);
            }
        }

        $createDefaultWarehouse = array_key_exists('create_default_warehouse', $payload)
            ? (bool) $payload['create_default_warehouse']
            : true;
        $createDefaultCashRegister = array_key_exists('create_default_cash_register', $payload)
            ? (bool) $payload['create_default_cash_register']
            : true;

        $companyId = 0;
        $branchId = 0;
        $roleId = 0;
        $adminUserId = 0;
        $defaultWarehouseId = null;

        DB::transaction(function () use (
            $payload,
            $authUser,
            $taxId,
            $adminUsername,
            $planCode,
            $presetCode,
            $readRate,
            $writeRate,
            $reportsRate,
            $createDefaultWarehouse,
            $createDefaultCashRegister,
            &$companyId,
            &$branchId,
            &$roleId,
            &$adminUserId,
            &$defaultWarehouseId
        ) {
            $companyId = (int) DB::table('core.companies')->insertGetId([
                'tax_id' => $taxId,
                'legal_name' => trim((string) $payload['legal_name']),
                'trade_name' => isset($payload['trade_name']) ? trim((string) $payload['trade_name']) : null,
                'email' => $payload['email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'address' => $payload['address'] ?? null,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $branchCode = trim((string) ($payload['main_branch_code'] ?? '001'));
            $branchName = trim((string) ($payload['main_branch_name'] ?? 'Sucursal Principal'));

            $branchId = (int) DB::table('core.branches')->insertGetId([
                'company_id' => $companyId,
                'code' => $branchCode,
                'name' => $branchName,
                'address' => $payload['address'] ?? null,
                'is_main' => true,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($this->tableExists('core', 'company_settings')) {
                DB::table('core.company_settings')->updateOrInsert(
                    ['company_id' => $companyId],
                    [
                        'address' => $payload['address'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                        'email' => $payload['email'] ?? null,
                        'updated_at' => now(),
                    ]
                );
            }

            if ($createDefaultWarehouse && $this->tableExists('inventory', 'warehouses')) {
                $defaultWarehouseId = (int) DB::table('inventory.warehouses')->insertGetId([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'code' => trim((string) ($payload['default_warehouse_code'] ?? 'ALM-001')),
                    'name' => trim((string) ($payload['default_warehouse_name'] ?? 'Almacen Principal')),
                    'address' => $payload['address'] ?? null,
                    'status' => 1,
                ]);
            }

            if ($createDefaultCashRegister && $this->tableExists('sales', 'cash_registers')) {
                DB::table('sales.cash_registers')->insert([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $defaultWarehouseId,
                    'code' => trim((string) ($payload['default_cash_register_code'] ?? 'CAJA-001')),
                    'name' => trim((string) ($payload['default_cash_register_name'] ?? 'Caja Principal')),
                    'status' => 1,
                    'created_at' => now(),
                ]);
            }

            $roleId = (int) DB::table('auth.roles')->insertGetId([
                'company_id' => $companyId,
                'code' => 'ADMIN',
                'name' => 'Administrador',
                'status' => 1,
            ]);

            $templateAdminRole = DB::table('auth.roles')
                ->where('company_id', (int) $authUser->company_id)
                ->whereRaw('UPPER(code) = ?', ['ADMIN'])
                ->first(['id']);

            $templateAccess = collect();
            if ($templateAdminRole) {
                $templateAccess = DB::table('auth.role_module_access')
                    ->where('role_id', (int) $templateAdminRole->id)
                    ->get();
            }

            if ($templateAccess->isNotEmpty()) {
                foreach ($templateAccess as $row) {
                    DB::table('auth.role_module_access')->insert([
                        'role_id' => $roleId,
                        'module_id' => (int) $row->module_id,
                        'can_view' => (bool) $row->can_view,
                        'can_create' => (bool) $row->can_create,
                        'can_update' => (bool) $row->can_update,
                        'can_delete' => (bool) $row->can_delete,
                        'can_export' => (bool) $row->can_export,
                        'can_approve' => (bool) $row->can_approve,
                        'field_rules' => $row->field_rules,
                        'data_scope_rules' => $row->data_scope_rules,
                        'updated_at' => now(),
                    ]);
                }
            } else {
                $moduleIds = DB::table('appcfg.modules')->where('status', 1)->pluck('id')->all();
                foreach ($moduleIds as $moduleId) {
                    DB::table('auth.role_module_access')->insert([
                        'role_id' => $roleId,
                        'module_id' => (int) $moduleId,
                        'can_view' => true,
                        'can_create' => true,
                        'can_update' => true,
                        'can_delete' => true,
                        'can_export' => true,
                        'can_approve' => true,
                        'updated_at' => now(),
                    ]);
                }
            }

            $adminUserId = (int) DB::table('auth.users')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'username' => $adminUsername,
                'password_hash' => Hash::make((string) $payload['admin_password']),
                'first_name' => trim((string) $payload['admin_first_name']),
                'last_name' => isset($payload['admin_last_name']) ? trim((string) $payload['admin_last_name']) : null,
                'email' => $payload['admin_email'] ?? null,
                'phone' => $payload['admin_phone'] ?? null,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('auth.user_roles')->insert([
                'user_id' => $adminUserId,
                'role_id' => $roleId,
            ]);

            if ($this->tableExists('appcfg', 'company_verticals') && $this->tableExists('appcfg', 'verticals')) {
                $verticalCode = isset($payload['vertical_code']) ? strtoupper(trim((string) $payload['vertical_code'])) : '';
                $vertical = null;
                if ($verticalCode !== '') {
                    $vertical = DB::table('appcfg.verticals')
                        ->whereRaw('UPPER(code) = ?', [$verticalCode])
                        ->where('status', 1)
                        ->first(['id']);
                }

                if (!$vertical) {
                    $vertical = DB::table('appcfg.verticals')
                        ->where('status', 1)
                        ->orderBy('name')
                        ->first(['id']);
                }

                if ($vertical) {
                    DB::table('appcfg.company_verticals')->insert([
                        'company_id' => $companyId,
                        'vertical_id' => (int) $vertical->id,
                        'is_primary' => true,
                        'status' => 1,
                        'effective_from' => now()->toDateString(),
                        'effective_to' => null,
                        'created_by' => $authUser->id,
                        'updated_by' => $authUser->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($this->tableExists('appcfg', 'company_rate_limits')) {
                DB::table('appcfg.company_rate_limits')->updateOrInsert(
                    ['company_id' => $companyId],
                    [
                        'is_enabled' => true,
                        'requests_per_minute' => $readRate,
                        'requests_per_minute_read' => $readRate,
                        'requests_per_minute_write' => $writeRate,
                        'requests_per_minute_reports' => $reportsRate,
                        'plan_code' => $planCode,
                        'last_preset_code' => $presetCode,
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $this->logCompanyRateLimitAudit(
                    $companyId,
                    'SINGLE',
                    $planCode,
                    $presetCode,
                    true,
                    $readRate,
                    $writeRate,
                    $reportsRate,
                    (int) $authUser->id
                );
            }

            if ($this->tableExists('appcfg', 'company_operational_limits')) {
                DB::table('appcfg.company_operational_limits')->updateOrInsert(
                    ['company_id' => $companyId],
                    [
                        'max_branches_enabled' => 1,
                        'max_warehouses_enabled' => 1,
                        'max_cash_registers_enabled' => 1,
                        'max_cash_registers_per_warehouse' => 1,
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]
                );
            }

            $this->ensureCompanyAccessLink($companyId, (string) $payload['legal_name'], $taxId, $authUser->id);
        });

        return response()->json([
            'message' => 'Empresa creada correctamente desde panel admin',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'admin_user_id' => $adminUserId,
            'admin_role_id' => $roleId,
        ], 201);
    }

    public function resetAdminCompanyPassword(Request $request, $companyId)
    {
        $companyId = (int) $companyId;

        $adminUser = DB::table('auth.users as u')
            ->join('auth.user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('u.company_id', $companyId)
            ->where('u.status', 1)
            ->whereRaw("UPPER(r.code) = 'ADMIN'")
            ->orderBy('u.id')
            ->first(['u.id', 'u.username', 'u.email']);

        if (!$adminUser) {
            return response()->json([
                'message' => 'No se encontró un usuario administrador activo para esta empresa.',
            ], 404);
        }

        $newPassword = $this->generateSecurePassword();

        DB::table('auth.users')
            ->where('id', (int) $adminUser->id)
            ->update([
                'password_hash' => Hash::make($newPassword),
                'updated_at' => now(),
            ]);

        return response()->json([
            'username' => $adminUser->username,
            'email' => $adminUser->email,
            'new_password' => $newPassword,
            'message' => 'Contraseña reseteada correctamente.',
        ]);
    }

    private function generateSecurePassword(int $length = 12): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    public function operationalLimits(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $usage = $this->fetchCompanyOperationalUsage($companyId);
        $companyLimits = $this->fetchCompanyOperationalLimits($companyId);
        $platformLimits = $this->fetchPlatformLimits();

        return response()->json([
            'company_id' => $companyId,
            'platform_limits' => $platformLimits,
            'company_limits' => $companyLimits,
            'usage' => $usage,
            'is_over_limit' => [
                'branches' => $usage['enabled_branches'] > $companyLimits['max_branches_enabled'],
                'warehouses' => $usage['enabled_warehouses'] > $companyLimits['max_warehouses_enabled'],
                'cash_registers' => $usage['enabled_cash_registers'] > $companyLimits['max_cash_registers_enabled'],
                'companies' => $usage['enabled_companies'] > $platformLimits['max_companies_enabled'],
            ],
        ]);
    }

    public function updateOperationalLimits(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$this->tableExists('appcfg', 'platform_limits') || !$this->tableExists('appcfg', 'company_operational_limits')) {
            return response()->json([
                'message' => 'Operational limits tables not found. Execute SQL incremental first.',
                'required_script' => 'docs/reingenieria/multialmacen_multicaja_limits_20260310.sql',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'max_companies_enabled' => 'nullable|integer|min:1|max:10000',
            'max_branches_enabled' => 'nullable|integer|min:1|max:10000',
            'max_warehouses_enabled' => 'nullable|integer|min:1|max:10000',
            'max_cash_registers_enabled' => 'nullable|integer|min:1|max:10000',
            'max_cash_registers_per_warehouse' => 'nullable|integer|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        DB::transaction(function () use ($payload, $companyId, $authUser) {
            if (isset($payload['max_companies_enabled'])) {
                DB::table('appcfg.platform_limits')->updateOrInsert(
                    ['id' => 1],
                    [
                        'max_companies_enabled' => (int) $payload['max_companies_enabled'],
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]
                );
            }

            $updates = [];
            if (isset($payload['max_branches_enabled'])) {
                $updates['max_branches_enabled'] = (int) $payload['max_branches_enabled'];
            }
            if (isset($payload['max_warehouses_enabled'])) {
                $updates['max_warehouses_enabled'] = (int) $payload['max_warehouses_enabled'];
            }
            if (isset($payload['max_cash_registers_enabled'])) {
                $updates['max_cash_registers_enabled'] = (int) $payload['max_cash_registers_enabled'];
            }
            if (isset($payload['max_cash_registers_per_warehouse'])) {
                $updates['max_cash_registers_per_warehouse'] = (int) $payload['max_cash_registers_per_warehouse'];
            }

            if (!empty($updates)) {
                $updates['updated_by'] = $authUser->id;
                $updates['updated_at'] = now();

                DB::table('appcfg.company_operational_limits')->updateOrInsert(
                    ['company_id' => $companyId],
                    $updates
                );
            }
        });

        $usage = $this->fetchCompanyOperationalUsage($companyId);
        $companyLimits = $this->fetchCompanyOperationalLimits($companyId);
        $platformLimits = $this->fetchPlatformLimits();

        return response()->json([
            'message' => 'Operational limits updated',
            'company_id' => $companyId,
            'platform_limits' => $platformLimits,
            'company_limits' => $companyLimits,
            'usage' => $usage,
        ]);
    }

    public function commerceSettings(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id');

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 403);
            }
        }

        $companyRows = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', self::COMMERCE_FEATURE_CODES)
            ->get()
            ->keyBy('feature_code');

        $branchRows = collect();
        if ($branchId !== null) {
            $branchRows = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereIn('feature_code', self::COMMERCE_FEATURE_CODES)
                ->get()
                ->keyBy('feature_code');
        }

        $features = collect(self::COMMERCE_FEATURE_CODES)->map(function ($code) use ($companyId, $companyRows, $branchRows) {
            $companyRow = $companyRows->get($code);
            $branchRow = $branchRows->get($code);
            $companyConfig = $companyRow ? $this->decodeJsonConfig($companyRow->config) : null;
            $branchConfig = $branchRow ? $this->decodeJsonConfig($branchRow->config) : null;

            $isEnabled = $branchRow && $branchRow->is_enabled !== null
                ? (bool) $branchRow->is_enabled
                : ($companyRow ? (bool) $companyRow->is_enabled : false);

            $resolvedConfig = is_array($companyConfig) || is_array($branchConfig)
                ? array_merge(is_array($companyConfig) ? $companyConfig : [], is_array($branchConfig) ? $branchConfig : [])
                : ($branchConfig ?? $companyConfig);

            $verticalPreference = $this->resolveVerticalFeaturePreference($companyId, (string) $code);
            if ($verticalPreference['resolved']) {
                if ($verticalPreference['is_enabled'] !== null) {
                    $isEnabled = (bool) $verticalPreference['is_enabled'];
                }
                if ($verticalPreference['config'] !== null) {
                    $resolvedConfig = $verticalPreference['config'];
                }
            }

            return [
                'feature_code' => $code,
                'is_enabled' => $isEnabled,
                'config' => $resolvedConfig,
                'vertical_source' => $verticalPreference['source'],
            ];
        })->values();

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'features' => $features,
        ]);
    }

    public function updateCommerceSettings(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'features' => 'required|array|min:1',
            'features.*.feature_code' => 'required|string|in:PRODUCT_MULTI_UOM,PRODUCT_UOM_CONVERSIONS,PRODUCT_WHOLESALE_PRICING,INVENTORY_PRODUCTS_BY_PROFILE,INVENTORY_PRODUCT_MASTERS_BY_PROFILE,SALES_CUSTOMER_PRICE_PROFILE,SALES_SELLER_TO_CASHIER,SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL,SALES_ANTICIPO_ENABLED,SALES_TAX_BRIDGE,SALES_DETRACCION_ENABLED,SALES_RETENCION_ENABLED,SALES_PERCEPCION_ENABLED,PURCHASES_DETRACCION_ENABLED,PURCHASES_RETENCION_COMPRADOR_ENABLED,PURCHASES_RETENCION_PROVEEDOR_ENABLED,PURCHASES_PERCEPCION_ENABLED',
            'features.*.is_enabled' => 'required|boolean',
            'features.*.config' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        if ($branchId !== null) {
            $branchExists = DB::table('core.branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 403);
            }
        }

        foreach ($payload['features'] as $feature) {
            $match = [
                'company_id' => $companyId,
                'feature_code' => $feature['feature_code'],
            ];

            if ($branchId !== null) {
                $match['branch_id'] = $branchId;
                DB::table('appcfg.branch_feature_toggles')->updateOrInsert(
                    $match,
                    [
                        'is_enabled' => (bool) $feature['is_enabled'],
                        'config' => array_key_exists('config', $feature) ? $this->encodeJsonConfig($feature['config']) : null,
                        'updated_by' => $authUser->id,
                        'updated_at' => now(),
                    ]
                );
                continue;
            }

            DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                $match,
                [
                    'is_enabled' => (bool) $feature['is_enabled'],
                    'config' => array_key_exists('config', $feature) ? $this->encodeJsonConfig($feature['config']) : null,
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]
            );
        }

        return $this->commerceSettings($request);
    }

    private function decodeJsonConfig($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    private function encodeJsonConfig($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded);
            }

            return json_encode($value);
        }

        return json_encode($value);
    }

    private function resolveVerticalFeaturePreference(int $companyId, string $featureCode): array
    {
        $cacheKey = $companyId . ':' . strtoupper(trim($featureCode));
        if (array_key_exists($cacheKey, $this->verticalFeaturePreferenceCache)) {
            return $this->verticalFeaturePreferenceCache[$cacheKey];
        }

        $default = [
            'resolved' => false,
            'is_enabled' => null,
            'config' => null,
            'source' => null,
        ];

        if (!$this->tableExists('appcfg', 'verticals')
            || !$this->tableExists('appcfg', 'company_verticals')
            || !$this->tableExists('appcfg', 'vertical_feature_templates')
            || !$this->tableExists('appcfg', 'company_vertical_feature_overrides')) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $activeVertical = $this->resolveActiveCompanyVertical($companyId);
        if ($activeVertical === null) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $normalizedFeatureCode = strtoupper(trim($featureCode));

        $override = DB::table('appcfg.company_vertical_feature_overrides')
            ->where('company_id', $companyId)
            ->where('vertical_id', (int) $activeVertical['id'])
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled', 'config']);

        if ($override && ($override->is_enabled !== null || $override->config !== null)) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => $override->is_enabled !== null ? (bool) $override->is_enabled : null,
                'config' => $override->config !== null ? $this->decodeJsonConfig($override->config) : null,
                'source' => 'COMPANY_VERTICAL_OVERRIDE',
            ];
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $template = DB::table('appcfg.vertical_feature_templates')
            ->where('vertical_id', (int) $activeVertical['id'])
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled', 'config']);

        if ($template) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => $template->is_enabled !== null ? (bool) $template->is_enabled : null,
                'config' => $template->config !== null ? $this->decodeJsonConfig($template->config) : null,
                'source' => 'VERTICAL_TEMPLATE',
            ];
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
        return $default;
    }

    private function resolveActiveCompanyVertical(int $companyId): ?array
    {
        if (array_key_exists($companyId, $this->activeVerticalCache)) {
            return $this->activeVerticalCache[$companyId];
        }

        if (!$this->tableExists('appcfg', 'verticals') || !$this->tableExists('appcfg', 'company_verticals')) {
            $this->activeVerticalCache[$companyId] = null;
            return null;
        }

        $row = DB::table('appcfg.company_verticals as cv')
            ->join('appcfg.verticals as v', 'v.id', '=', 'cv.vertical_id')
            ->where('cv.company_id', $companyId)
            ->where('cv.status', 1)
            ->where('v.status', 1)
            ->where('cv.is_primary', true)
            ->select('v.id', 'v.code', 'v.name')
            ->first();

        if (!$row) {
            $this->activeVerticalCache[$companyId] = null;
            return null;
        }

        $resolved = [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ];

        $this->activeVerticalCache[$companyId] = $resolved;
        return $resolved;
    }

    private function fetchPlatformLimits(): array
    {
        $enabledCompanies = (int) DB::table('core.companies')
            ->where('status', 1)
            ->count();

        if (!$this->tableExists('appcfg', 'platform_limits')) {
            return [
                'max_companies_enabled' => max(1, $enabledCompanies),
            ];
        }

        $row = DB::table('appcfg.platform_limits')
            ->select('max_companies_enabled')
            ->where('id', 1)
            ->first();

        return [
            'max_companies_enabled' => $row ? (int) $row->max_companies_enabled : max(1, $enabledCompanies),
        ];
    }

    private function fetchCompanyOperationalLimits(int $companyId): array
    {
        $usage = $this->fetchCompanyOperationalUsage($companyId);

        if (!$this->tableExists('appcfg', 'company_operational_limits')) {
            return [
                'max_branches_enabled' => max(1, $usage['enabled_branches']),
                'max_warehouses_enabled' => max(1, $usage['enabled_warehouses']),
                'max_cash_registers_enabled' => max(1, $usage['enabled_cash_registers']),
                'max_cash_registers_per_warehouse' => 1,
            ];
        }

        $row = DB::table('appcfg.company_operational_limits')
            ->select('max_branches_enabled', 'max_warehouses_enabled', 'max_cash_registers_enabled', 'max_cash_registers_per_warehouse')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return [
                'max_branches_enabled' => max(1, $usage['enabled_branches']),
                'max_warehouses_enabled' => max(1, $usage['enabled_warehouses']),
                'max_cash_registers_enabled' => max(1, $usage['enabled_cash_registers']),
                'max_cash_registers_per_warehouse' => 1,
            ];
        }

        return [
            'max_branches_enabled' => (int) $row->max_branches_enabled,
            'max_warehouses_enabled' => (int) $row->max_warehouses_enabled,
            'max_cash_registers_enabled' => (int) $row->max_cash_registers_enabled,
            'max_cash_registers_per_warehouse' => (int) ($row->max_cash_registers_per_warehouse ?? 1),
        ];
    }

    private function fetchCompanyOperationalUsage(int $companyId): array
    {
        return [
            'enabled_companies' => (int) DB::table('core.companies')->where('status', 1)->count(),
            'enabled_branches' => (int) DB::table('core.branches')->where('company_id', $companyId)->where('status', 1)->count(),
            'enabled_warehouses' => (int) DB::table('inventory.warehouses')->where('company_id', $companyId)->where('status', 1)->count(),
            'enabled_cash_registers' => (int) DB::table('sales.cash_registers')->where('company_id', $companyId)->where('status', 1)->count(),
        ];
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function ensureCompanyAccessLinksForCompanies($companies): void
    {
        if (!$this->tableExists('appcfg', 'company_access_links')) {
            return;
        }

        foreach ($companies as $company) {
            $companyId = (int) $company->id;
            $exists = DB::table('appcfg.company_access_links')
                ->where('company_id', $companyId)
                ->exists();

            if ($exists) {
                continue;
            }

            $this->ensureCompanyAccessLink(
                $companyId,
                (string) ($company->legal_name ?? ''),
                $company->tax_id !== null ? (string) $company->tax_id : null,
                null
            );
        }
    }

    private function ensureCompanyAccessLink(int $companyId, string $legalName, ?string $taxId, ?int $actorId): string
    {
        if (!$this->tableExists('appcfg', 'company_access_links')) {
            return '';
        }

        $existing = DB::table('appcfg.company_access_links')
            ->where('company_id', $companyId)
            ->first(['access_slug']);

        if ($existing && !empty($existing->access_slug)) {
            $currentSlug = (string) $existing->access_slug;
            if (!$this->isSensitiveCompanyAccessSlug($currentSlug)) {
                return $currentSlug;
            }
        }

        $slug = $this->generateCompanyAccessSlug($companyId, $legalName, $taxId);

        DB::table('appcfg.company_access_links')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'access_slug' => $slug,
                'is_active' => true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $slug;
    }

    private function generateCompanyAccessSlug(int $companyId, string $legalName, ?string $taxId): string
    {
        $hashSeed = hash('sha256', 'company-link|' . $companyId . '|' . (string) config('app.key'));
        $base = 'emp-' . strtolower(substr($hashSeed, 0, 12));
        $candidate = $base;
        $suffix = 1;

        while (DB::table('appcfg.company_access_links')
            ->where('access_slug', $candidate)
            ->where('company_id', '!=', $companyId)
            ->exists()) {
            $suffix++;
            $candidate = $base . '-' . $suffix;
        }

        return $candidate;
    }

    private function isSensitiveCompanyAccessSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return true;
        }

        // Legacy format exposed tax identifiers in path, for example ruc-10455923951-1.
        if (Str::startsWith($normalized, 'ruc-')) {
            return true;
        }

        return false;
    }

    private function resolveFrontendAppUrl(): string
    {
        $base = (string) env('FRONTEND_APP_URL', 'http://127.0.0.1:5173');
        return rtrim($base, '/');
    }

    private function buildCompanyAccessUrl(string $slug): string
    {
        return $this->resolveFrontendAppUrl() . '/t/' . rawurlencode($slug);
    }

    private function companyRateLimitPresets(int $defaultRead, int $defaultWrite, int $defaultReports): array
    {
        return [
            [
                'code' => 'BASIC',
                'name' => 'Basic',
                'requests_per_minute_read' => max(1000, (int) round($defaultRead * 0.65)),
                'requests_per_minute_write' => max(700, (int) round($defaultWrite * 0.6)),
                'requests_per_minute_reports' => max(300, (int) round($defaultReports * 0.55)),
            ],
            [
                'code' => 'PRO',
                'name' => 'Pro',
                'requests_per_minute_read' => $defaultRead,
                'requests_per_minute_write' => $defaultWrite,
                'requests_per_minute_reports' => $defaultReports,
            ],
            [
                'code' => 'ENTERPRISE',
                'name' => 'Enterprise',
                'requests_per_minute_read' => max($defaultRead, 6000),
                'requests_per_minute_write' => max($defaultWrite, 4000),
                'requests_per_minute_reports' => max($defaultReports, 1500),
            ],
        ];
    }

    private function logCompanyRateLimitAudit(
        int $companyId,
        string $actionType,
        string $planCode,
        ?string $presetCode,
        bool $isEnabled,
        int $readPerMinute,
        int $writePerMinute,
        int $reportsPerMinute,
        ?int $appliedBy
    ): void {
        if (!$this->tableExists('appcfg', 'company_rate_limit_audit')) {
            return;
        }

        DB::table('appcfg.company_rate_limit_audit')->insert([
            'company_id' => $companyId,
            'action_type' => $actionType,
            'plan_code' => $planCode,
            'preset_code' => $presetCode,
            'is_enabled' => $isEnabled,
            'requests_per_minute_read' => $readPerMinute,
            'requests_per_minute_write' => $writePerMinute,
            'requests_per_minute_reports' => $reportsPerMinute,
            'applied_by' => $appliedBy,
            'created_at' => now(),
        ]);
    }
    // ─────────────────────────────────────────────────────────
    // Perfil de empresa
    // ─────────────────────────────────────────────────────────

    public function companyProfile(Request $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $company = DB::table('core.companies')
            ->select('id', 'tax_id', 'legal_name', 'trade_name', 'status')
            ->where('id', $companyId)
            ->first();

        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $settings = null;
        if ($this->tableExists('core', 'company_settings')) {
            $settings = DB::table('core.company_settings')
                ->where('company_id', $companyId)
                ->first();
        }

        $logoUrl = null;
        if ($settings && $settings->logo_path) {
            $logoUrl = Storage::disk('public')->exists($settings->logo_path)
                ? Storage::disk('public')->url($settings->logo_path)
                : null;
        }

        // Extract location fields from extra_data
        $extraData = $settings
            ? json_decode((string) ($settings->extra_data ?? '{}'), true) ?? []
            : [];

        return response()->json([
            'company_id'      => $companyId,
            'tax_id'          => $company->tax_id,
            'legal_name'      => $company->legal_name,
            'trade_name'      => $company->trade_name,
            'status'          => (int) $company->status,
            'address'         => $settings->address    ?? null,
            'phone'           => $settings->phone       ?? null,
            'telefono_movil'  => $extraData['telefono_movil'] ?? null,
            'telefono_fijo'   => $extraData['telefono_fijo'] ?? null,
            'email'           => $settings->email       ?? null,
            'website'         => $settings->website     ?? null,
            'ubigeo'          => $extraData['ubigeo'] ?? null,
            'departamento'    => $extraData['departamento'] ?? null,
            'provincia'       => $extraData['provincia'] ?? null,
            'distrito'        => $extraData['distrito'] ?? null,
            'urbanizacion'    => $extraData['urbanizacion'] ?? null,
            'sunat_secondary_user' => $extraData['sunat_secondary_user'] ?? null,
            'sunat_secondary_pass' => $extraData['sunat_secondary_pass'] ?? null,
            'client_id'       => $extraData['client_id'] ?? null,
            'client_secret'   => $extraData['client_secret'] ?? null,
            'logo_url'        => $logoUrl,
            'has_cert'        => $settings && !empty($settings->cert_path),
            'bank_accounts'   => $settings
                ? json_decode((string) $settings->bank_accounts, true) ?? []
                : [],
        ]);
    }

    public function updateCompanyProfile(Request $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id'    => 'nullable|integer|min:1',
            'tax_id'        => 'nullable|string|max:20',
            'legal_name'    => 'nullable|string|max:200',
            'trade_name'    => 'nullable|string|max:200',
            'address'       => 'nullable|string|max:500',
            'phone'         => 'nullable|string|max:60',
            'telefono_movil'=> 'nullable|string|max:60',
            'telefono_fijo' => 'nullable|string|max:60',
            'email'         => 'nullable|email|max:200',
            'website'       => 'nullable|url|max:300',
            'ubigeo'        => 'nullable|string|max:6',
            'departamento'  => 'nullable|string|max:100',
            'provincia'     => 'nullable|string|max:100',
            'distrito'      => 'nullable|string|max:100',
            'urbanizacion'  => 'nullable|string|max:100',
            'sunat_secondary_user' => 'nullable|string|max:100',
            'sunat_secondary_pass' => 'nullable|string|max:100',
            'client_id'     => 'nullable|string|max:200',
            'client_secret' => 'nullable|string|max:500',
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.bank_name'     => 'required_with:bank_accounts.*|string|max:100',
            'bank_accounts.*.account_number'=> 'required_with:bank_accounts.*|string|max:50',
            'bank_accounts.*.currency'      => 'nullable|string|max:10',
            'bank_accounts.*.account_type'  => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload   = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        // Actualizar datos basicos de la empresa
        $companyUpdates = array_filter([
            'tax_id'     => $payload['tax_id']     ?? null,
            'legal_name' => $payload['legal_name'] ?? null,
            'trade_name' => $payload['trade_name'] ?? null,
        ], fn($v) => $v !== null);

        if (!empty($companyUpdates)) {
            DB::table('core.companies')
                ->where('id', $companyId)
                ->update($companyUpdates);
        }

        // Actualizar configuracion extendida si la tabla existe
        if ($this->tableExists('core', 'company_settings')) {
            $settingsUpdates = [
                'updated_at' => now(),
            ];
            if (array_key_exists('address', $payload)) {
                $settingsUpdates['address'] = $payload['address'];
            }
            if (array_key_exists('phone', $payload)) {
                $settingsUpdates['phone'] = $payload['phone'];
            }
            if (array_key_exists('email', $payload)) {
                $settingsUpdates['email'] = $payload['email'];
            }
            if (array_key_exists('website', $payload)) {
                $settingsUpdates['website'] = $payload['website'];
            }
            if (array_key_exists('bank_accounts', $payload)) {
                $settingsUpdates['bank_accounts'] = json_encode($payload['bank_accounts'] ?? []);
            }

            $extraDataFields = ['ubigeo', 'departamento', 'provincia', 'distrito', 'urbanizacion', 'telefono_movil', 'telefono_fijo', 'sunat_secondary_user', 'sunat_secondary_pass', 'client_id', 'client_secret'];
            $hasExtraDataUpdates = false;
            foreach ($extraDataFields as $field) {
                if (array_key_exists($field, $payload)) {
                    $hasExtraDataUpdates = true;
                    break;
                }
            }

            if ($hasExtraDataUpdates) {
                $currentSettings = DB::table('core.company_settings')
                    ->where('company_id', $companyId)
                    ->first();

                $currentExtra = $currentSettings
                    ? json_decode((string) ($currentSettings->extra_data ?? '{}'), true) ?? []
                    : [];

                foreach ($extraDataFields as $field) {
                    if (array_key_exists($field, $payload)) {
                        if ($payload[$field] === null || $payload[$field] === '') {
                            unset($currentExtra[$field]);
                        } else {
                            $currentExtra[$field] = $payload[$field];
                        }
                    }
                }

                $settingsUpdates['extra_data'] = json_encode($currentExtra);
            }

            DB::table('core.company_settings')->updateOrInsert(
                ['company_id' => $companyId],
                $settingsUpdates
            );
        }

        return $this->companyProfile($request, $companyIgvRateService);
    }

    public function igvSettings(Request $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        return response()->json([
            'company_id' => $companyId,
            'active_rate' => $companyIgvRateService->resolveActiveRate($companyId),
        ]);
    }

    public function updateIgvSettings(Request $request, CompanyIgvRateService $companyIgvRateService)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'active_igv_rate_percent' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $activeRate = $companyIgvRateService->setActiveRatePercent($companyId, (float) $payload['active_igv_rate_percent']);

        return response()->json([
            'company_id' => $companyId,
            'active_rate' => $activeRate,
        ]);
    }

    public function uploadCompanyLogo(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id', $authUser->company_id));

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$request->hasFile('logo')) {
            return response()->json(['message' => 'No se recibio el archivo logo'], 422);
        }

        $file = $request->file('logo');

        // Validar MIME y tamaño (max 2 MB)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return response()->json(['message' => 'Tipo de archivo no permitido. Use JPG, PNG, GIF o WEBP'], 422);
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return response()->json(['message' => 'El logo no puede superar 2 MB'], 422);
        }

        $ext      = $file->getClientOriginalExtension();
        $path     = "logos/company_{$companyId}." . strtolower($ext);

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        if ($this->tableExists('core', 'company_settings')) {
            DB::table('core.company_settings')->updateOrInsert(
                ['company_id' => $companyId],
                ['logo_path' => $path, 'updated_at' => now()]
            );
        }

        return response()->json([
            'message'  => 'Logo actualizado',
            'logo_url' => Storage::disk('public')->url($path),
        ]);
    }

    public function uploadCompanyCert(Request $request, TaxBridgeService $taxBridgeService)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id', $authUser->company_id));

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if (!$request->hasFile('cert')) {
            return response()->json(['message' => 'No se recibio el certificado'], 422);
        }

        $certPassword = $request->input('cert_password', '');
        if ($certPassword === '') {
            return response()->json(['message' => 'La contrasena del certificado es requerida'], 422);
        }

        $file = $request->file('cert');

        // Validar extension (.p12, .pfx, .pem)
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['p12', 'pfx', 'pem'], true)) {
            return response()->json(['message' => 'Solo se aceptan certificados .p12, .pfx o .pem'], 422);
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return response()->json(['message' => 'El certificado no puede superar 2 MB'], 422);
        }

        // Almacenar en disco LOCAL (nunca publico)
        $certPath = "certs/company_{$companyId}.{$ext}";
        Storage::disk('local')->put($certPath, file_get_contents($file->getRealPath()));

        // Cifrar contrasena
        $encPassword = Crypt::encryptString($certPassword);

        if ($this->tableExists('core', 'company_settings')) {
            DB::table('core.company_settings')->updateOrInsert(
                ['company_id' => $companyId],
                [
                    'cert_path'         => $certPath,
                    'cert_password_enc' => $encPassword,
                    'updated_at'        => now(),
                ]
            );
        }

        $company = DB::table('core.companies')
            ->where('id', $companyId)
            ->select('tax_id', 'legal_name', 'trade_name')
            ->first();

        $settings = null;
        if ($this->tableExists('core', 'company_settings')) {
            $settings = DB::table('core.company_settings')
                ->where('company_id', $companyId)
                ->select('address', 'phone', 'email', 'extra_data')
                ->first();
        }

        $extraData = $settings && $settings->extra_data
            ? (json_decode((string) $settings->extra_data, true) ?: [])
            : [];

        $companyPhone = (string) ($settings->phone ?? '');
        $companyMobilePhone = (string) (($extraData['telefono_movil'] ?? $extraData['mobile_phone'] ?? '') ?: $companyPhone);
        $companyLandlinePhone = (string) (($extraData['telefono_fijo'] ?? $extraData['landline_phone'] ?? '') ?: $companyPhone);

        $bridgePayload = [
            'empresa' => (string) ($company->legal_name ?? ''),
            'nomcom' => (string) ($company->trade_name ?? ''),
            'ruc' => (string) ($company->tax_id ?? ''),
            'domicilio_fiscal' => (string) ($settings->address ?? ''),
            'dep' => (string) ($extraData['departamento'] ?? ''),
            'pro' => (string) ($extraData['provincia'] ?? ''),
            'dis' => (string) ($extraData['distrito'] ?? ''),
            'urb' => (string) ($extraData['urbanizacion'] ?? ''),
            'ubigeo' => (string) ($extraData['ubigeo'] ?? ''),
            'correo' => (string) ($settings->email ?? ''),
            'telefono_movil' => $companyMobilePhone,
            'telefono_fijo' => $companyLandlinePhone,
            'user' => (string) ($extraData['sunat_secondary_user'] ?? ''),
            'pass' => (string) ($extraData['sunat_secondary_pass'] ?? ''),
            'pass_certificado' => $certPassword,
        ];

        try {
            $bridgeResult = $taxBridgeService->registerCertificate(
                $companyId,
                null,
                $bridgePayload,
                $file->getRealPath(),
                $file->getClientOriginalName()
            );
        } catch (TaxBridgeException $e) {
            return response()->json([
                'message' => 'Certificado guardado localmente, pero no se pudo registrar en el puente: ' . $e->getMessage(),
                'has_cert' => true,
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Certificado guardado localmente, pero ocurrio un error al registrar en el puente',
                'has_cert' => true,
                'bridge_error' => substr($e->getMessage(), 0, 500),
            ], 500);
        }

        $legacyCode = $bridgeResult['legacy_code'] ?? null;

        if ($legacyCode === 0) {
            return response()->json([
                'message' => 'Clave del certificado incorrecta',
                'has_cert' => true,
                'bridge_debug' => [
                    'endpoint' => $bridgeResult['endpoint'] ?? '',
                    'method' => 'POST',
                    'payload' => $bridgeResult['payload'] ?? [],
                ],
                'bridge_response' => $bridgeResult['json_response'] ?? ($bridgeResult['raw_response'] ?? null),
            ], 422);
        }

        if ($legacyCode !== 1 && $legacyCode !== 3) {
            return response()->json([
                'message' => 'Certificado guardado localmente, pero el puente devolvio una respuesta no esperada',
                'has_cert' => true,
                'bridge_debug' => [
                    'endpoint' => $bridgeResult['endpoint'] ?? '',
                    'method' => 'POST',
                    'payload' => $bridgeResult['payload'] ?? [],
                ],
                'bridge_response' => $bridgeResult['json_response'] ?? ($bridgeResult['raw_response'] ?? null),
            ], 502);
        }

        return response()->json([
            'message'  => 'Certificado digital actualizado y registrado en puente',
            'has_cert' => true,
            'bridge_debug' => [
                'endpoint' => $bridgeResult['endpoint'] ?? '',
                'method' => 'POST',
                'payload' => $bridgeResult['payload'] ?? [],
            ],
            'bridge_response' => $bridgeResult['json_response'] ?? ($bridgeResult['raw_response'] ?? null),
        ]);
    }
}
