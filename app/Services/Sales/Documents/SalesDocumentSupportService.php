<?php

namespace App\Services\Sales\Documents;

use Illuminate\Support\Facades\DB;

class SalesDocumentSupportService
{
    public function decodeDocumentMetadata($rawMetadata): array
    {
        if ($rawMetadata === null || $rawMetadata === '') {
            return [];
        }

        if (is_array($rawMetadata)) {
            return $rawMetadata;
        }

        $decoded = json_decode((string) $rawMetadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function hasActiveChildConversions(int $companyId, int $sourceDocumentId): bool
    {
        return DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceDocumentId])
            ->exists();
    }

    public function isCommerceFeatureEnabledForContextWithDefault(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled): bool
    {
        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->select('is_enabled')
                ->first();

            if ($branchRow && $branchRow->is_enabled !== null) {
                return (bool) $branchRow->is_enabled;
            }
        }

        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->select('is_enabled')
            ->first();

        if ($companyRow && $companyRow->is_enabled !== null) {
            return (bool) $companyRow->is_enabled;
        }

        return $defaultEnabled;
    }

    public function resolveLineConversion(int $companyId, $product, array $item, ?int $itemUnitId): array
    {
        $qty = (float) ($item['qty'] ?? 0);

        if (!$product || !$product->unit_id) {
            $factor = isset($item['conversion_factor']) ? (float) $item['conversion_factor'] : 1.0;
            if ($factor <= 0) {
                $factor = 1.0;
            }

            $qtyBase = isset($item['qty_base']) ? (float) $item['qty_base'] : ($qty * $factor);
            if ($qtyBase <= 0) {
                $qtyBase = $qty;
            }

            $baseUnitPrice = isset($item['base_unit_price']) ? (float) $item['base_unit_price'] : ((float) $item['unit_price'] / max($factor, 0.00000001));

            return [
                'conversion_factor' => $factor,
                'qty_base' => $qtyBase,
                'base_unit_price' => $baseUnitPrice,
            ];
        }

        $baseUnitId = (int) $product->unit_id;
        $lineUnitId = $itemUnitId ?: $baseUnitId;

        if (isset($item['conversion_factor']) && (float) $item['conversion_factor'] > 0) {
            $factor = (float) $item['conversion_factor'];
        } else {
            $factor = $this->resolveConversionFactor($companyId, (int) $product->id, $lineUnitId, $baseUnitId);
        }

        if ($factor <= 0) {
            throw new SalesDocumentException('Invalid conversion factor for product #' . $product->id);
        }

        $qtyBase = isset($item['qty_base']) && (float) $item['qty_base'] > 0
            ? (float) $item['qty_base']
            : ($qty * $factor);

        $baseUnitPrice = isset($item['base_unit_price']) && (float) $item['base_unit_price'] >= 0
            ? (float) $item['base_unit_price']
            : ((float) $item['unit_price'] / max($factor, 0.00000001));

        return [
            'conversion_factor' => $factor,
            'qty_base' => $qtyBase,
            'base_unit_price' => $baseUnitPrice,
        ];
    }

    public function isSellerActor(string $roleProfile, string $roleCode): bool
    {
        if ($this->isAdminActor($roleCode)) {
            return false;
        }

        if ($roleProfile === 'SELLER') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        return strpos($roleCode, 'VENDED') !== false || strpos($roleCode, 'SELLER') !== false;
    }

    public function isCashierActor(string $roleProfile, string $roleCode): bool
    {
        if ($roleProfile === 'CASHIER') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        if ($this->isAdminActor($roleCode)) {
            return true;
        }

        return strpos($roleCode, 'CAJA') !== false || strpos($roleCode, 'CAJER') !== false || strpos($roleCode, 'CASHIER') !== false;
    }

    public function isAdminActor(string $roleCode): bool
    {
        return $roleCode !== '' && strpos($roleCode, 'ADMIN') !== false;
    }

    public function resolveAuthRoleContext(int $userId, int $companyId): array
    {
        $this->ensureCompanyRoleProfilesTable();

        $row = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($companyId) {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', $companyId);
            })
            ->where('ur.user_id', $userId)
            ->where('r.company_id', $companyId)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code', 'crp.functional_profile as role_profile')
            ->first();

        return [
            'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
            'role_profile' => $row && $row->role_profile !== null ? (string) $row->role_profile : null,
        ];
    }

    private function resolveConversionFactor(int $companyId, int $productId, int $lineUnitId, int $baseUnitId): float
    {
        if ($lineUnitId === $baseUnitId) {
            return 1.0;
        }

        $direct = DB::table('inventory.product_uom_conversions')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('from_unit_id', $lineUnitId)
            ->where('to_unit_id', $baseUnitId)
            ->where('status', 1)
            ->value('conversion_factor');

        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }

        $inverse = DB::table('inventory.product_uom_conversions')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('from_unit_id', $baseUnitId)
            ->where('to_unit_id', $lineUnitId)
            ->where('status', 1)
            ->value('conversion_factor');

        if ($inverse !== null && (float) $inverse > 0) {
            return 1 / (float) $inverse;
        }

        throw new SalesDocumentException('Missing conversion from unit ' . $lineUnitId . ' to base unit ' . $baseUnitId . ' for product #' . $productId);
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
}
