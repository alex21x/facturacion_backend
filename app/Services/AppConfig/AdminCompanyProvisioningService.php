<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\AdminCompanyProvisioningRepository;
use Illuminate\Support\Facades\Hash;

class AdminCompanyProvisioningService
{
    public function __construct(
        private AdminCompanyProvisioningRepository $repository,
        private FeatureLabelService $featureLabelService,
        private CompanyAccessLinkService $companyAccessLinkService,
        private OperationalLimitsService $operationalLimitsService
    ) {
    }

    public function taxIdExists(string $taxId): bool
    {
        return $this->repository->taxIdExists($taxId);
    }

    public function adminUsernameExists(string $username): bool
    {
        return $this->repository->adminUsernameExists($username);
    }

    public function createAdminCompany(array $payload, object $authUser, array $limits): array
    {
        $createDefaultWarehouse = true;
        $createDefaultCashRegister = array_key_exists('create_default_cash_register', $payload)
            ? (bool) $payload['create_default_cash_register']
            : true;

        $taxBridgeValues = [
            'fiscal_mode' => 'SUMMARY',
            'strict_identity_validation' => true,
            'auto_receipt_for_nodoc' => true,
            'receipt_series' => 'B001',
            'receipt_next_number' => 1,
            'updated_at' => now()->toIso8601String(),
        ];

        $result = $this->repository->createAdminCompany([
            'tax_id' => trim((string) $payload['tax_id']),
            'legal_name' => trim((string) $payload['legal_name']),
            'trade_name' => isset($payload['trade_name']) ? trim((string) $payload['trade_name']) : null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'address' => $payload['address'] ?? null,
            'branch_code' => trim((string) ($payload['main_branch_code'] ?? '001')),
            'branch_name' => trim((string) ($payload['main_branch_name'] ?? 'Sucursal Principal')),
            'create_default_warehouse' => $createDefaultWarehouse,
            'default_warehouse_code' => trim((string) ($payload['default_warehouse_code'] ?? 'ALM-001')),
            'default_warehouse_name' => trim((string) ($payload['default_warehouse_name'] ?? 'Almacen Principal')),
            'create_default_cash_register' => $createDefaultCashRegister,
            'default_cash_register_code' => trim((string) ($payload['default_cash_register_code'] ?? 'CAJA-001')),
            'default_cash_register_name' => trim((string) ($payload['default_cash_register_name'] ?? 'Caja Principal')),
            'admin_username' => trim((string) $payload['admin_username']),
            'admin_password_hash' => Hash::make((string) $payload['admin_password']),
            'admin_first_name' => trim((string) $payload['admin_first_name']),
            'admin_last_name' => isset($payload['admin_last_name']) ? trim((string) $payload['admin_last_name']) : null,
            'admin_email' => $payload['admin_email'] ?? null,
            'admin_phone' => $payload['admin_phone'] ?? null,
            'template_company_id' => (int) $authUser->company_id,
            'vertical_code' => isset($payload['vertical_code']) ? (string) $payload['vertical_code'] : null,
            'actor_user_id' => (int) $authUser->id,
            'plan_code' => (string) $limits['plan_code'],
            'preset_code' => $limits['preset_code'] !== null ? (string) $limits['preset_code'] : null,
            'read_rate' => (int) $limits['read_rate'],
            'write_rate' => (int) $limits['write_rate'],
            'reports_rate' => (int) $limits['reports_rate'],
            'has_company_settings' => $this->tableExists('core', 'company_settings'),
            'has_cash_registers' => $this->tableExists('sales', 'cash_registers'),
            'has_company_verticals' => $this->tableExists('appcfg', 'company_verticals'),
            'has_verticals' => $this->tableExists('appcfg', 'verticals'),
            'has_company_rate_limits' => $this->tableExists('appcfg', 'company_rate_limits'),
            'has_company_operational_limits' => $this->tableExists('appcfg', 'company_operational_limits'),
            'has_company_feature_toggles' => $this->tableExists('appcfg', 'company_feature_toggles'),
            'has_company_feature_toggles_created_at' => $this->columnExists('appcfg', 'company_feature_toggles', 'created_at'),
            'sales_tax_bridge_config_json' => $this->encodeJsonConfig($taxBridgeValues),
        ]);

        if ($this->tableExists('appcfg', 'company_rate_limit_audit')) {
            $this->operationalLimitsService->logCompanyRateLimitAudit([
                'company_id' => $result['company_id'],
                'action_type' => 'SINGLE',
                'plan_code' => (string) $limits['plan_code'],
                'preset_code' => $limits['preset_code'] !== null ? (string) $limits['preset_code'] : null,
                'is_enabled' => true,
                'requests_per_minute_read' => (int) $limits['read_rate'],
                'requests_per_minute_write' => (int) $limits['write_rate'],
                'requests_per_minute_reports' => (int) $limits['reports_rate'],
                'applied_by' => (int) $authUser->id,
                'created_at' => now(),
            ]);
        }

        $this->companyAccessLinkService->ensureCompanyAccessLink(
            (int) $result['company_id'],
            trim((string) $payload['legal_name']),
            trim((string) $payload['tax_id']),
            (int) $authUser->id
        );

        return $result;
    }

    public function findActiveAdminUser(int $companyId, bool $includeLastTempPassword = false): ?object
    {
        return $this->repository->findActiveAdminUser($companyId, $includeLastTempPassword);
    }

    public function updateAdminPassword(int $userId, string $plainPassword, bool $persistTempPassword): void
    {
        $encryptedTempPassword = $persistTempPassword ? encrypt($plainPassword) : null;

        $this->repository->updateAdminUserPassword(
            $userId,
            Hash::make($plainPassword),
            $encryptedTempPassword
        );
    }

    public function repairCompanyAdminRoleAfterRestore(int $companyId): void
    {
        $this->repository->repairCompanyAdminRoleAfterRestore($companyId);
    }

    private function tableExists(string $schema, string $table): bool
    {
        return $this->featureLabelService->tableExists($schema, $table);
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        return $this->featureLabelService->columnExists($schema, $table, $column);
    }

    private function encodeJsonConfig($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
