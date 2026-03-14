<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AppConfigController extends Controller
{
    private const COMMERCE_FEATURE_CODES = [
        'PRODUCT_MULTI_UOM',
        'PRODUCT_UOM_CONVERSIONS',
        'PRODUCT_WHOLESALE_PRICING',
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

        return response()->json([
            'company' => $company,
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

        $features = $featureCodes->map(function ($featureCode) use ($companyFeatures, $branchFeatures) {
            $company = $companyFeatures->get($featureCode);
            $branch = $branchFeatures->get($featureCode);

            $isEnabled = false;
            if ($branch && $branch->is_enabled !== null) {
                $isEnabled = (bool) $branch->is_enabled;
            } elseif ($company && $company->is_enabled !== null) {
                $isEnabled = (bool) $company->is_enabled;
            }

            return [
                'feature_code' => $featureCode,
                'is_enabled' => $isEnabled,
                'company_enabled' => $company ? (bool) $company->is_enabled : null,
                'branch_enabled' => $branch ? (bool) $branch->is_enabled : null,
                'company_config' => $company ? $company->config : null,
                'branch_config' => $branch ? $branch->config : null,
            ];
        })->values();

        return response()->json([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'features' => $features,
        ]);
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

        if ($companyId !== (int) $authUser->company_id) {
            return response()->json([
                'message' => 'Invalid company scope',
            ], 403);
        }

        $rows = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereIn('feature_code', self::COMMERCE_FEATURE_CODES)
            ->get()
            ->keyBy('feature_code');

        $features = collect(self::COMMERCE_FEATURE_CODES)->map(function ($code) use ($rows) {
            $row = $rows->get($code);

            return [
                'feature_code' => $code,
                'is_enabled' => $row ? (bool) $row->is_enabled : false,
                'config' => $row ? $row->config : null,
            ];
        })->values();

        return response()->json([
            'company_id' => $companyId,
            'features' => $features,
        ]);
    }

    public function updateCommerceSettings(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id' => 'nullable|integer|min:1',
            'features' => 'required|array|min:1',
            'features.*.feature_code' => 'required|string|in:PRODUCT_MULTI_UOM,PRODUCT_UOM_CONVERSIONS,PRODUCT_WHOLESALE_PRICING',
            'features.*.is_enabled' => 'required|boolean',
            'features.*.config' => 'nullable|array',
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

        foreach ($payload['features'] as $feature) {
            DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'feature_code' => $feature['feature_code'],
                ],
                [
                    'is_enabled' => (bool) $feature['is_enabled'],
                    'config' => array_key_exists('config', $feature) ? json_encode($feature['config']) : null,
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]
            );
        }

        return $this->commerceSettings($request);
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
            ];
        }

        $row = DB::table('appcfg.company_operational_limits')
            ->select('max_branches_enabled', 'max_warehouses_enabled', 'max_cash_registers_enabled')
            ->where('company_id', $companyId)
            ->first();

        if (!$row) {
            return [
                'max_branches_enabled' => max(1, $usage['enabled_branches']),
                'max_warehouses_enabled' => max(1, $usage['enabled_warehouses']),
                'max_cash_registers_enabled' => max(1, $usage['enabled_cash_registers']),
            ];
        }

        return [
            'max_branches_enabled' => (int) $row->max_branches_enabled,
            'max_warehouses_enabled' => (int) $row->max_warehouses_enabled,
            'max_cash_registers_enabled' => (int) $row->max_cash_registers_enabled,
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
}
