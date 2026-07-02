<?php

namespace App\Infrastructure\Repositories\Sales\Documents;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesDocumentSupportService
{
    /** @var array<string, bool> */
    private array $featureToggleMemo = [];

    private ?bool $companyRoleProfilesTableExists = null;

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
        $memoKey = sprintf('%d:%s:%s:%d', $companyId, $branchId ?? 'all', $featureCode, $defaultEnabled ? 1 : 0);
        if (array_key_exists($memoKey, $this->featureToggleMemo)) {
            return $this->featureToggleMemo[$memoKey];
        }

        $cacheKey = sprintf('sales:feature_toggle:%d:%s:%s:%d', $companyId, $branchId ?? 'all', $featureCode, $defaultEnabled ? 1 : 0);

        $resolved = Cache::remember($cacheKey, now()->addSeconds(15), function () use ($companyId, $branchId, $featureCode, $defaultEnabled): bool {
            $rows = $branchId !== null
                ? DB::table('appcfg.branch_feature_toggles')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('feature_code', $featureCode)
                    ->selectRaw("'BRANCH' as scope, is_enabled")
                    ->unionAll(
                        DB::table('appcfg.company_feature_toggles')
                            ->where('company_id', $companyId)
                            ->where('feature_code', $featureCode)
                            ->selectRaw("'COMPANY' as scope, is_enabled")
                    )
                    ->get()
                : DB::table('appcfg.company_feature_toggles')
                    ->where('company_id', $companyId)
                    ->where('feature_code', $featureCode)
                    ->selectRaw("'COMPANY' as scope, is_enabled")
                    ->get();

            $branchRow = $rows->first(fn ($row) => strtoupper((string) ($row->scope ?? '')) === 'BRANCH');
            if ($branchRow && $branchRow->is_enabled !== null) {
                return (bool) $branchRow->is_enabled;
            }

            $companyRow = $rows->first(fn ($row) => strtoupper((string) ($row->scope ?? '')) === 'COMPANY');
            if ($companyRow && $companyRow->is_enabled !== null) {
                return (bool) $companyRow->is_enabled;
            }

            return $defaultEnabled;
        });

        $this->featureToggleMemo[$memoKey] = (bool) $resolved;

        return (bool) $resolved;
    }

    public function resolveLineConversion(int $companyId, $product, array $item, ?int $itemUnitId): array
    {
        $qty = (float) ($item['qty'] ?? ($item['quantity'] ?? 0));

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

        // Always derive base quantity server-side for product-backed lines.
        // Client-provided qty_base can become stale when qty/unit changes and causes kardex mismatches.
        $qtyBase = $qty * $factor;

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
        $query = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('r.company_id', $companyId)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code');

        if ($this->hasCompanyRoleProfilesTable()) {
            $query->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($companyId) {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', $companyId);
            });
            $query->addSelect('crp.functional_profile as role_profile');
        }

        $row = $query->first();

        return [
            'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
            'role_profile' => $row && property_exists($row, 'role_profile') && $row->role_profile !== null
                ? (string) $row->role_profile
                : null,
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

    private function hasCompanyRoleProfilesTable(): bool
    {
        if ($this->companyRoleProfilesTableExists !== null) {
            return $this->companyRoleProfilesTableExists;
        }

        $cacheKey = 'sales:support:table_exists:appcfg.company_role_profiles';
        $exists = Cache::remember($cacheKey, now()->addMinutes(5), function (): bool {
            return DB::table('information_schema.tables')
                ->where('table_schema', 'appcfg')
                ->where('table_name', 'company_role_profiles')
                ->exists();
        });

        $this->companyRoleProfilesTableExists = (bool) $exists;

        return $this->companyRoleProfilesTableExists;
    }
}
