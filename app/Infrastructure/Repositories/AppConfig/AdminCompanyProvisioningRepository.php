<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminCompanyProvisioningRepository
{
    public function taxIdExists(string $taxId): bool
    {
        return DB::table('core.companies')
            ->whereRaw('UPPER(tax_id) = ?', [strtoupper($taxId)])
            ->exists();
    }

    public function adminUsernameExists(string $username): bool
    {
        return DB::table('auth.users')
            ->whereRaw('UPPER(username) = ?', [strtoupper($username)])
            ->exists();
    }

    public function createAdminCompany(array $input): array
    {
        $companyId = 0;
        $branchId = 0;
        $roleId = 0;
        $adminUserId = 0;
        $defaultWarehouseId = null;

        DB::transaction(function () use ($input, &$companyId, &$branchId, &$roleId, &$adminUserId, &$defaultWarehouseId): void {
            $now = now();

            $companyId = (int) DB::table('core.companies')->insertGetId([
                'tax_id' => $input['tax_id'],
                'legal_name' => $input['legal_name'],
                'trade_name' => $input['trade_name'],
                'email' => $input['email'],
                'phone' => $input['phone'],
                'address' => $input['address'],
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $branchId = (int) DB::table('core.branches')->insertGetId([
                'company_id' => $companyId,
                'code' => $input['branch_code'],
                'name' => $input['branch_name'],
                'address' => $input['address'],
                'is_main' => true,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($input['has_company_settings']) {
                DB::table('core.company_settings')->updateOrInsert(
                    ['company_id' => $companyId],
                    [
                        'address' => $input['address'],
                        'phone' => $input['phone'],
                        'email' => $input['email'],
                        'updated_at' => $now,
                    ]
                );
            }

            if ($input['create_default_warehouse']) {
                $existingWarehouse = DB::table('inventory.warehouses')
                    ->where('company_id', $companyId)
                    ->where('status', 1)
                    ->where(function ($query) use ($branchId): void {
                        $query->where('branch_id', $branchId)
                            ->orWhereNull('branch_id');
                    })
                    ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
                    ->orderBy('name')
                    ->first(['id']);

                if ($existingWarehouse) {
                    $defaultWarehouseId = (int) $existingWarehouse->id;
                } else {
                    $defaultWarehouseId = (int) DB::table('inventory.warehouses')->insertGetId([
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'code' => $input['default_warehouse_code'],
                        'name' => $input['default_warehouse_name'],
                        'address' => $input['address'],
                        'status' => 1,
                    ]);
                }
            }

            if ($input['create_default_cash_register'] && $input['has_cash_registers']) {
                DB::table('sales.cash_registers')->insert([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $defaultWarehouseId,
                    'code' => $input['default_cash_register_code'],
                    'name' => $input['default_cash_register_name'],
                    'status' => 1,
                    'created_at' => $now,
                ]);
            }

            $roleId = (int) DB::table('auth.roles')->insertGetId([
                'company_id' => $companyId,
                'code' => 'ADMIN',
                'name' => 'Administrador',
                'status' => 1,
            ]);

            $templateAccess = $this->resolveTemplateAccessRows($input['template_company_id']);
            $this->seedRoleModuleAccess($roleId, $templateAccess, $now);

            $adminUserId = (int) DB::table('auth.users')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'username' => $input['admin_username'],
                'password_hash' => $input['admin_password_hash'],
                'first_name' => $input['admin_first_name'],
                'last_name' => $input['admin_last_name'],
                'email' => $input['admin_email'],
                'phone' => $input['admin_phone'],
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('auth.user_roles')->insert([
                'user_id' => $adminUserId,
                'role_id' => $roleId,
            ]);

            if ($input['has_company_verticals'] && $input['has_verticals']) {
                $verticalId = $this->resolveVerticalId($input['vertical_code']);
                if ($verticalId !== null) {
                    DB::table('appcfg.company_verticals')->insert([
                        'company_id' => $companyId,
                        'vertical_id' => $verticalId,
                        'is_primary' => true,
                        'status' => 1,
                        'effective_from' => $now->toDateString(),
                        'effective_to' => null,
                        'created_by' => $input['actor_user_id'],
                        'updated_by' => $input['actor_user_id'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if ($input['has_company_rate_limits']) {
                DB::table('appcfg.company_rate_limits')->updateOrInsert(
                    ['company_id' => $companyId],
                    [
                        'is_enabled' => true,
                        'requests_per_minute' => $input['read_rate'],
                        'requests_per_minute_read' => $input['read_rate'],
                        'requests_per_minute_write' => $input['write_rate'],
                        'requests_per_minute_reports' => $input['reports_rate'],
                        'plan_code' => $input['plan_code'],
                        'last_preset_code' => $input['preset_code'],
                        'updated_by' => $input['actor_user_id'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            if ($input['has_company_operational_limits']) {
                DB::table('appcfg.company_operational_limits')->updateOrInsert(
                    ['company_id' => $companyId],
                    [
                        'max_branches_enabled' => 1,
                        'max_warehouses_enabled' => 1,
                        'max_cash_registers_enabled' => 1,
                        'max_cash_registers_per_warehouse' => 1,
                        'updated_by' => $input['actor_user_id'],
                        'updated_at' => $now,
                    ]
                );
            }

            if ($input['has_company_feature_toggles']) {
                $taxBridgeValues = [
                    'is_enabled' => true,
                    'config' => $input['sales_tax_bridge_config_json'],
                    'updated_by' => $input['actor_user_id'],
                    'updated_at' => $now,
                ];

                if ($input['has_company_feature_toggles_created_at']) {
                    $taxBridgeValues['created_at'] = $now;
                }

                DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                    ['company_id' => $companyId, 'feature_code' => 'SALES_TAX_BRIDGE'],
                    $taxBridgeValues
                );
            }
        });

        return [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'role_id' => $roleId,
            'admin_user_id' => $adminUserId,
        ];
    }

    public function findActiveAdminUser(int $companyId, bool $includeLastTempPassword = false): ?\App\Application\DTOs\AppConfig\AdminCompanyUserDTO
    {
        $fields = ['u.id', 'u.username', 'u.email'];
        if ($includeLastTempPassword) {
            $fields[] = 'u.last_temp_password';
        }

        $user = DB::table('auth.users as u')
            ->join('auth.user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('u.company_id', $companyId)
            ->where('u.status', 1)
            ->whereRaw("UPPER(r.code) = 'ADMIN'")
            ->orderBy('u.id')
            ->first($fields);

        return $user ? \App\Application\DTOs\AppConfig\AdminCompanyUserDTO::fromRow($user) : null;
    }

    public function updateAdminUserPassword(int $userId, string $passwordHash, ?string $encryptedTempPassword): void
    {
        $values = [
            'password_hash' => $passwordHash,
            'updated_at' => now(),
        ];

        if ($encryptedTempPassword !== null) {
            $values['last_temp_password'] = $encryptedTempPassword;
        }

        DB::table('auth.users')
            ->where('id', $userId)
            ->update($values);
    }

    public function repairCompanyAdminRoleAfterRestore(int $companyId): void
    {
        $activeUserIds = DB::table('auth.users')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderByRaw("CASE WHEN LOWER(username) IN ('admin', 'administrador') THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($activeUserIds)) {
            return;
        }

        $candidateUserId = (int) $activeUserIds[0];

        $adminRole = DB::table('auth.roles')
            ->where('company_id', $companyId)
            ->whereRaw("UPPER(code) = 'ADMIN'")
            ->orderBy('id')
            ->first(['id']);

        $adminRoleId = $adminRole ? (int) $adminRole->id : 0;
        if ($adminRoleId <= 0) {
            $adminRoleId = (int) DB::table('auth.roles')->insertGetId([
                'company_id' => $companyId,
                'code' => 'ADMIN',
                'name' => 'Administrador',
                'status' => 1,
            ]);
        }

        if ($adminRoleId <= 0) {
            return;
        }

        DB::table('auth.user_roles')->updateOrInsert(
            [
                'user_id' => $candidateUserId,
                'role_id' => $adminRoleId,
            ],
            []
        );

        $permissionIds = DB::table('auth.permissions')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($permissionIds as $permissionId) {
            DB::table('auth.role_permissions')->updateOrInsert(
                [
                    'role_id' => $adminRoleId,
                    'permission_id' => $permissionId,
                ],
                []
            );
        }

        $moduleIds = DB::table('appcfg.modules')->where('status', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($moduleIds as $moduleId) {
            DB::table('auth.role_module_access')->updateOrInsert(
                [
                    'role_id' => $adminRoleId,
                    'module_id' => $moduleId,
                ],
                [
                    'can_view' => true,
                    'can_create' => true,
                    'can_update' => true,
                    'can_delete' => true,
                    'can_export' => true,
                    'can_approve' => true,
                    'updated_at' => now(),
                ]
            );
        }

        $fieldIds = DB::table('appcfg.ui_fields')->where('status', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($fieldIds as $fieldId) {
            DB::table('auth.role_ui_field_access')->updateOrInsert(
                [
                    'role_id' => $adminRoleId,
                    'field_id' => $fieldId,
                ],
                [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_filter' => true,
                ]
            );
        }
    }

    private function resolveTemplateAccessRows(int $templateCompanyId): Collection
    {
        $templateAdminRole = DB::table('auth.roles')
            ->where('company_id', $templateCompanyId)
            ->whereRaw('UPPER(code) = ?', ['ADMIN'])
            ->first(['id']);

        if (!$templateAdminRole) {
            return collect();
        }

        return DB::table('auth.role_module_access')
            ->where('role_id', (int) $templateAdminRole->id)
            ->get();
    }

    private function seedRoleModuleAccess(int $roleId, Collection $templateAccess, $now): void
    {
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
                    'updated_at' => $now,
                ]);
            }

            return;
        }

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
                'updated_at' => $now,
            ]);
        }
    }

    private function resolveVerticalId(?string $verticalCode): ?int
    {
        $normalizedCode = strtoupper(trim((string) $verticalCode));
        if ($normalizedCode !== '') {
            $vertical = DB::table('appcfg.verticals')
                ->whereRaw('UPPER(code) = ?', [$normalizedCode])
                ->where('status', 1)
                ->first(['id']);

            if ($vertical) {
                return (int) $vertical->id;
            }
        }

        $fallback = DB::table('appcfg.verticals')
            ->where('status', 1)
            ->orderBy('name')
            ->first(['id']);

        return $fallback ? (int) $fallback->id : null;
    }
}
