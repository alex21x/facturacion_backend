<?php

namespace App\Infrastructure\Repositories\Sales;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SalesLookupRepository
{
    private const DAY_START_SUFFIX = ' 00:00:00';
    private const DAY_END_SUFFIX = ' 23:59:59.999999';
    private const DOCUMENT_KINDS_BOOTSTRAP_CACHE_TTL_SECONDS = 600;
    private const VERTICAL_FEATURE_LOOKUP_CACHE_TTL_SECONDS = 5;
    private const TABLE_EXISTS_CACHE_TTL_SECONDS = 300;

    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    /** @var array<string, array<int, string>> */
    private array $tableColumnsCache = [];

    public function branchExists(int $companyId, int $branchId): bool
    {
        return DB::table('core.branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    public function activeWarehouseExistsInBranchScope(int $companyId, int $warehouseId, int $branchId): bool
    {
        return DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })
            ->exists();
    }

    public function resolveDefaultWarehouseIdByBranchScope(int $companyId, int $branchId): ?int
    {
        $warehouse = DB::table('inventory.warehouses')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
            ->orderBy('name')
            ->first();

        return $warehouse ? (int) $warehouse->id : null;
    }

    public function listActiveCurrencies(): Collection
    {
        return DB::table('core.currencies')
            ->select('id', 'code', 'name', 'symbol', 'is_default')
            ->where('status', 1)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function listActivePaymentTypes(): Collection
    {
        return DB::table('master.payment_types')
            ->select([
                'id',
                DB::raw("COALESCE(NULLIF(TRIM(comment), ''), CONCAT('PM', id::text)) as code"),
                'name',
            ])
            ->where(function (Builder $query): void {
                $query->where('is_active', 1)
                    ->orWhereIn('status', [1, 2]);
            })
            ->orderBy('name')
            ->get();
    }

    public function listPaymentMethodsForMasterData(): Collection
    {
        return DB::table('master.payment_types')
            ->select([
                'id',
                DB::raw("COALESCE(NULLIF(TRIM(comment), ''), CONCAT('PM', id::text)) as code"),
                'name',
                DB::raw('CASE WHEN COALESCE(is_active, 0) = 1 OR COALESCE(status, 0) IN (1, 2) THEN 1 ELSE 0 END as status'),
            ])
            ->orderBy('name')
            ->get();
    }

    public function createPaymentMethod(array $payload): int
    {
        try {
            return (int) DB::transaction(function () use ($payload) {
            $name = trim((string) ($payload['name'] ?? ''));
            $code = strtoupper(trim((string) ($payload['code'] ?? '')));

            if ($name === '') {
                throw new \RuntimeException('Payment method name is required');
            }

            $nameExists = DB::table('master.payment_types')
                ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper($name)])
                ->exists();

            if ($nameExists) {
                throw new \RuntimeException('Payment method name already exists');
            }

            if ($code !== '') {
                $codeExists = DB::table('master.payment_types')
                    ->whereRaw("UPPER(TRIM(COALESCE(comment, ''))) = ?", [$code])
                    ->exists();

                if ($codeExists) {
                    throw new \RuntimeException('Payment method code already exists');
                }
            }

            $lastRow = DB::table('master.payment_types')
                ->select('id')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $nextId = ((int) ($lastRow->id ?? 0)) + 1;
            $normalizedStatus = (int) ($payload['status'] ?? 1);

            DB::table('master.payment_types')->insert([
                'id' => $nextId,
                'name' => $name,
                'comment' => $code,
                'is_active' => $normalizedStatus === 1 ? 1 : 0,
                'status' => $normalizedStatus,
            ]);

            return $nextId;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $message = strtolower((string) $e->getMessage());

            if (str_contains($message, 'payment_types_name_key')) {
                throw new \RuntimeException('Payment method name already exists');
            }

            if (str_contains($message, 'payment_types_comment_key')) {
                throw new \RuntimeException('Payment method code already exists');
            }

            throw $e;
        }
    }

    public function paymentMethodExists(int $id): bool
    {
        return DB::table('master.payment_types')->where('id', $id)->exists();
    }

    public function updatePaymentMethod(int $id, array $updates): void
    {
        if (array_key_exists('name', $updates)) {
            $name = trim((string) $updates['name']);
            if ($name !== '') {
                $nameExists = DB::table('master.payment_types')
                    ->where('id', '<>', $id)
                    ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper($name)])
                    ->exists();

                if ($nameExists) {
                    throw new \RuntimeException('Payment method name already exists');
                }
            }
        }

        if (array_key_exists('comment', $updates)) {
            $code = strtoupper(trim((string) $updates['comment']));
            if ($code !== '') {
                $codeExists = DB::table('master.payment_types')
                    ->where('id', '<>', $id)
                    ->whereRaw("UPPER(TRIM(COALESCE(comment, ''))) = ?", [$code])
                    ->exists();

                if ($codeExists) {
                    throw new \RuntimeException('Payment method code already exists');
                }
            }
        }

        try {
            DB::table('master.payment_types')->where('id', $id)->update($updates);
        } catch (\Illuminate\Database\QueryException $e) {
            $message = strtolower((string) $e->getMessage());

            if (str_contains($message, 'payment_types_name_key')) {
                throw new \RuntimeException('Payment method name already exists');
            }

            if (str_contains($message, 'payment_types_comment_key')) {
                throw new \RuntimeException('Payment method code already exists');
            }

            throw $e;
        }
    }

    public function findCompanyById(int $companyId, array $columns): ?\App\Application\DTOs\AppConfig\CompanyProfileDTO
    {
        $columns = array_values(array_unique(array_merge($columns, ['id', 'status'])));

        $company = DB::table('core.companies')
            ->select($columns)
            ->where('id', $companyId)
            ->first();

        return $company ? \App\Application\DTOs\AppConfig\CompanyProfileDTO::fromRow($company) : null;
    }

    public function findLatestCompanySettings(
        int $companyId,
        array $columns,
        bool $preferRowsWithLogo,
        bool $orderByUpdatedAt,
        bool $orderByCreatedAt
    ): ?\App\Application\DTOs\AppConfig\CompanySettingsDTO {
        $query = DB::table('core.company_settings')
            ->select($columns)
            ->where('company_id', $companyId);

        if ($preferRowsWithLogo) {
            $query->orderByRaw("CASE WHEN COALESCE(logo_path, '') <> '' THEN 0 ELSE 1 END");
        }
        if ($orderByUpdatedAt) {
            $query->orderByDesc('updated_at');
        }
        if ($orderByCreatedAt) {
            $query->orderByDesc('created_at');
        }

        $settings = $query->first();

        return $settings ? \App\Application\DTOs\AppConfig\CompanySettingsDTO::fromRow($settings) : null;
    }

    public function listSeriesNumbers(
        int $companyId,
        ?int $branchId,
        ?int $warehouseId,
        bool $enabledOnly,
        ?int $documentKindId,
        ?string $documentKindCode
    ): Collection {
        $query = DB::table('sales.series_numbers as sn')
            ->leftJoin('sales.document_kinds as dk', 'dk.id', '=', 'sn.document_kind_id')
            ->where('sn.company_id', $companyId)
            ->select([
                'sn.id',
                'sn.company_id',
                'sn.branch_id',
                'sn.warehouse_id',
                'sn.document_kind_id',
                DB::raw("COALESCE(dk.code, sn.document_kind) as document_kind"),
                'sn.series',
                'sn.current_number',
                'sn.number_padding',
                'sn.reset_policy',
                'sn.is_enabled',
            ])
            ->orderBy('document_kind')
            ->orderBy('sn.series');

        if ($branchId !== null) {
            $query->where('sn.branch_id', $branchId);
        }

        if ($warehouseId !== null) {
            $query->where('sn.warehouse_id', $warehouseId);
        }

        if ($documentKindId !== null) {
            $resolvedCode = strtoupper(trim((string) $documentKindCode));
            $query->where(function (Builder $nested) use ($documentKindId, $resolvedCode): void {
                $nested->where('sn.document_kind_id', $documentKindId)
                    ->orWhere(function (Builder $legacy) use ($resolvedCode): void {
                        $legacy->whereNull('sn.document_kind_id')
                            ->whereRaw('UPPER(sn.document_kind) = ?', [$resolvedCode]);
                    });
            });
        } elseif (is_string($documentKindCode) && trim($documentKindCode) !== '') {
            $query->whereRaw('UPPER(COALESCE(dk.code, sn.document_kind)) = ?', [strtoupper(trim($documentKindCode))]);
        }

        if ($enabledOnly) {
            $query->where('sn.is_enabled', true);
        }

        return $query->get();
    }

    public function listPriceTiersForCompany(int $companyId): Collection
    {
        return DB::table('sales.price_tiers')
            ->select('id', 'company_id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
            ->where('company_id', $companyId)
            ->orderBy('priority')
            ->orderBy('min_qty')
            ->get();
    }

    public function createSeries(int $companyId, int $authUserId, array $payload): int
    {
        $documentKindCode = strtoupper(trim((string) $payload['document_kind']));
        $documentKindId = $this->resolveDocumentKindIdByCode($documentKindCode);
        if ($documentKindId === null) {
            throw new \RuntimeException('Document kind not found');
        }

        return (int) DB::table('sales.series_numbers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'warehouse_id' => $payload['warehouse_id'] ?? null,
            'document_kind' => $documentKindCode,
            'document_kind_id' => $documentKindId,
            'series' => strtoupper(trim($payload['series'])),
            'current_number' => (int) ($payload['current_number'] ?? 0),
            'number_padding' => (int) ($payload['number_padding'] ?? 8),
            'reset_policy' => $payload['reset_policy'] ?? 'NONE',
            'is_enabled' => array_key_exists('is_enabled', $payload) ? (bool) $payload['is_enabled'] : true,
            'updated_by' => $authUserId,
            'updated_at' => now(),
        ]);
    }

    public function updateSeries(int $companyId, int $authUserId, int $id, array $payload): void
    {
        $exists = DB::table('sales.series_numbers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            throw new \RuntimeException('Series not found');
        }

        $updates = ['updated_by' => $authUserId, 'updated_at' => now()];

        foreach (['branch_id', 'warehouse_id', 'current_number', 'number_padding', 'reset_policy'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        if (array_key_exists('document_kind', $payload)) {
            $documentKindCode = strtoupper(trim((string) $payload['document_kind']));
            $documentKindId = $this->resolveDocumentKindIdByCode($documentKindCode);
            if ($documentKindId === null) {
                throw new \RuntimeException('Document kind not found');
            }

            $updates['document_kind'] = $documentKindCode;
            $updates['document_kind_id'] = $documentKindId;
        }

        if (!empty($payload['series'])) {
            $updates['series'] = strtoupper(trim($payload['series']));
        }

        if (array_key_exists('is_enabled', $payload)) {
            $updates['is_enabled'] = (bool) $payload['is_enabled'];
        }

        DB::table('sales.series_numbers')->where('id', $id)->update($updates);
    }

    public function resolveDocumentKindIdByCode(string $code): ?int
    {
        $this->ensureDocumentKindsTable();

        $row = DB::table('sales.document_kinds')
            ->whereRaw('UPPER(TRIM(code)) = ?', [strtoupper(trim($code))])
            ->select('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    public function resolveDocumentKindIdsByCodes(array $codes): array
    {
        $normalizedCodes = array_values(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $codes
        )));

        if ($normalizedCodes === []) {
            return [];
        }

        return DB::table('sales.document_kinds')
            ->whereIn(DB::raw('UPPER(code)'), $normalizedCodes)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function resolveDocumentKindAliasesByCodes(array $codes): array
    {
        $normalizedCodes = array_values(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $codes
        )));

        if ($normalizedCodes === []) {
            return [];
        }

        $aliases = $normalizedCodes;

        $rows = DB::table('sales.document_kinds')
            ->select('code', 'label')
            ->whereIn(DB::raw('UPPER(code)'), $normalizedCodes)
            ->get();

        foreach ($rows as $row) {
            $aliases[] = strtoupper(trim((string) ($row->code ?? '')));
            $aliases[] = strtoupper(trim((string) ($row->label ?? '')));
        }

        return array_values(array_unique(array_filter($aliases, static fn ($value) => $value !== '')));
    }

    public function resolveDocumentKindIdMapByCodes(array $codes): array
    {
        $normalizedCodes = array_values(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $codes
        )));

        if ($normalizedCodes === []) {
            return [];
        }

        $rows = DB::table('sales.document_kinds')
            ->select('id', 'code')
            ->whereIn(DB::raw('UPPER(code)'), $normalizedCodes)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $normalizedCode = strtoupper(trim((string) ($row->code ?? '')));
            if ($normalizedCode === '') {
                continue;
            }
            $map[$normalizedCode] = (int) $row->id;
        }

        return $map;
    }

    public function resolveCanonicalDocumentKindCode(string $documentKindValue, ?int $documentKindId = null): ?string
    {
        if ($documentKindId !== null) {
            $codeById = DB::table('sales.document_kinds')
                ->where('id', $documentKindId)
                ->value('code');

            if ($codeById !== null && trim((string) $codeById) !== '') {
                return strtoupper(trim((string) $codeById));
            }
        }

        $normalizedValue = strtoupper(trim($documentKindValue));
        if ($normalizedValue === '') {
            return null;
        }

        $row = DB::table('sales.document_kinds')
            ->select('code', 'label')
            ->where(function ($query) use ($normalizedValue) {
                $query->whereRaw('UPPER(TRIM(code)) = ?', [$normalizedValue])
                    ->orWhereRaw('UPPER(TRIM(label)) = ?', [$normalizedValue]);
            })
            ->first();

        if ($row && isset($row->code) && trim((string) $row->code) !== '') {
            return strtoupper(trim((string) $row->code));
        }

        return $normalizedValue;
    }

    public function ensureDocumentKindsTable(): void
    {
        $bootstrapCacheKey = 'sales_lookup:document_kinds_bootstrap:v3';
        if (Cache::get($bootstrapCacheKey) === true) {
            return;
        }

        if (!$this->tableExistsBySchemaAndName('sales', 'document_kinds')) {
            throw new \RuntimeException('Missing table sales.document_kinds. Run backend migrations before using sales lookups.');
        }

        $columns = $this->tableColumnsByQualifiedTable('sales.document_kinds');
        if (!in_array('sunat_code', $columns, true)) {
            throw new \RuntimeException('Missing column sales.document_kinds.sunat_code. Run backend migrations before using sales lookups.');
        }

        $defaults = [
            ['code' => 'QUOTATION',   'label' => 'Cotizacion',      'sort_order' => 10, 'sunat_code' => null],
            ['code' => 'SALES_ORDER', 'label' => 'Pedido de Venta', 'sort_order' => 20, 'sunat_code' => null],
            ['code' => 'INVOICE',     'label' => 'Factura',         'sort_order' => 30, 'sunat_code' => '01'],
            ['code' => 'RECEIPT',     'label' => 'Boleta',          'sort_order' => 40, 'sunat_code' => '03'],
            ['code' => 'CREDIT_NOTE', 'label' => 'Nota de Credito', 'sort_order' => 50, 'sunat_code' => '07'],
            ['code' => 'DEBIT_NOTE',  'label' => 'Nota de Debito',  'sort_order' => 60, 'sunat_code' => '08'],
        ];

        $defaultCodes = array_map(function (array $row): string {
            return (string) $row['code'];
        }, $defaults);

        $existingRows = DB::table('sales.document_kinds')
            ->select('code', 'sunat_code')
            ->whereIn('code', $defaultCodes)
            ->get()
            ->keyBy('code');

        foreach ($defaults as $row) {
            $existing = $existingRows->get($row['code']);
            if ($existing === null) {
                DB::table('sales.document_kinds')->insert([
                    'code' => $row['code'],
                    'label' => $row['label'],
                    'sort_order' => $row['sort_order'],
                    'sunat_code' => $row['sunat_code'],
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($row['sunat_code'] !== null) {
                DB::table('sales.document_kinds')
                    ->where('code', $row['code'])
                    ->whereNull('sunat_code')
                    ->update(['sunat_code' => $row['sunat_code'], 'updated_at' => now()]);
            }
        }

        Cache::put($bootstrapCacheKey, true, self::DOCUMENT_KINDS_BOOTSTRAP_CACHE_TTL_SECONDS);

    }

    public function listDocumentKindsCatalog(): Collection
    {
        $this->ensureDocumentKindsTable();

        return DB::table('sales.document_kinds')
            ->select('id', 'code', 'label', 'is_enabled')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'label' => (string) $row->label,
                    'is_enabled' => (bool) $row->is_enabled,
                ];
            })
            ->values();
    }

    public function createDocumentKind(int $companyId, int $authUserId, array $payload): void
    {
        $this->ensureDocumentKindsTable();

        $code = strtoupper(trim((string) $payload['code']));
        $label = trim((string) $payload['label']);
        $sortOrder = isset($payload['sort_order']) ? (int) $payload['sort_order'] : 999;
        $isEnabled = array_key_exists('is_enabled', $payload) ? (bool) $payload['is_enabled'] : true;

        $exists = DB::table('sales.document_kinds')->where('code', $code)->exists();
        if ($exists) {
            throw new \RuntimeException('Document kind code already exists');
        }

        DB::table('sales.document_kinds')->insert([
            'id' => DB::raw("nextval('sales.document_kinds_id_seq')"),
            'code' => $code,
            'label' => $label,
            'sort_order' => $sortOrder,
            'is_enabled' => $isEnabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('appcfg.company_feature_toggles')->updateOrInsert(
            [
                'company_id' => $companyId,
                'feature_code' => 'DOC_KIND_' . $code,
            ],
            [
                'is_enabled' => $isEnabled,
                'config' => json_encode(['managed_by' => 'masters']),
                'updated_by' => $authUserId,
                'updated_at' => now(),
            ]
        );
    }

    public function updateDocumentKind(int $companyId, int $authUserId, int $id, array $payload): void
    {
        $this->ensureDocumentKindsTable();

        $kind = DB::table('sales.document_kinds')->where('id', $id)->first(['id', 'code']);
        if (!$kind) {
            throw new \RuntimeException('Document kind not found');
        }

        $sourceCode = strtoupper(trim((string) $kind->code));
        $targetCode = array_key_exists('code', $payload)
            ? strtoupper(trim((string) $payload['code']))
            : $sourceCode;

        if ($targetCode !== $sourceCode) {
            $targetExists = DB::table('sales.document_kinds')
                ->where('code', $targetCode)
                ->where('id', '<>', $id)
                ->exists();

            if ($targetExists) {
                throw new \RuntimeException('Document kind code already exists: ' . $targetCode);
            }

            DB::table('sales.series_numbers')
                ->where('document_kind_id', $id)
                ->update(['document_kind' => $targetCode]);

            DB::table('sales.series_numbers')
                ->whereNull('document_kind_id')
                ->whereRaw("UPPER(TRIM(COALESCE(document_kind, ''))) = ?", [$sourceCode])
                ->update([
                    'document_kind' => $targetCode,
                    'document_kind_id' => $id,
                ]);

            DB::table('sales.document_sequences')
                ->where('document_kind_id', $id)
                ->update(['document_kind' => $targetCode]);

            DB::table('sales.commercial_documents')
                ->where('document_kind_id', $id)
                ->update(['document_kind' => $targetCode]);

            DB::table('sales.commercial_documents')
                ->whereNull('document_kind_id')
                ->whereRaw("UPPER(TRIM(COALESCE(document_kind, ''))) = ?", [$sourceCode])
                ->update([
                    'document_kind' => $targetCode,
                    'document_kind_id' => $id,
                ]);

            if (DB::table('information_schema.tables')->where('table_schema', 'billing')->where('table_name', 'documents')->exists()) {
                DB::table('billing.documents')
                    ->whereRaw("UPPER(TRIM(COALESCE(doc_type, ''))) = ?", [$sourceCode])
                    ->update(['doc_type' => $targetCode]);
            }

            DB::table('appcfg.company_feature_toggles')
                ->where('feature_code', 'DOC_KIND_' . $sourceCode)
                ->update(['feature_code' => 'DOC_KIND_' . $targetCode]);
        }

        $updates = ['updated_at' => now()];
        if (array_key_exists('code', $payload)) {
            $updates['code'] = $targetCode;
        }
        if (array_key_exists('label', $payload) && trim((string) $payload['label']) !== '') {
            $updates['label'] = trim((string) $payload['label']);
        }
        if (array_key_exists('is_enabled', $payload)) {
            $updates['is_enabled'] = (bool) $payload['is_enabled'];
            DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'feature_code' => 'DOC_KIND_' . $targetCode,
                ],
                [
                    'is_enabled' => (bool) $payload['is_enabled'],
                    'config' => json_encode(['managed_by' => 'masters']),
                    'updated_by' => $authUserId,
                    'updated_at' => now(),
                ]
            );
        }
        if (array_key_exists('sort_order', $payload)) {
            $updates['sort_order'] = (int) $payload['sort_order'];
        }

        DB::table('sales.document_kinds')->where('id', $id)->update($updates);
    }

    public function findCommercialDocumentBranchId(int $companyId, int $documentId): ?int
    {
        $branchId = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->value('branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }

    public function findUserPasswordHashById(int $userId): ?string
    {
        $passwordHash = DB::table('auth.users')
            ->where('id', $userId)
            ->value('password_hash');

        return is_string($passwordHash) && $passwordHash !== '' ? $passwordHash : null;
    }

    public function paginateCommercialDocuments(int $companyId, array $filters, int $page, int $limit): array
    {
        $countQuery = DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId);

        $customerFilter = trim((string) ($filters['customer'] ?? ''));
        if ($customerFilter !== '') {
            $countQuery->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id');
        }

        $this->applyCommercialDocumentFilters($countQuery, $filters);

        $itemDiscountTotals = DB::table('sales.commercial_document_items as di')
            ->select([
                'di.document_id',
                DB::raw('SUM(COALESCE(di.discount_total, 0)) as item_discount_total'),
            ])
            ->whereExists(function (Builder $scope) use ($companyId): void {
                $scope->select(DB::raw('1'))
                    ->from('sales.commercial_documents as ds')
                    ->whereColumn('ds.id', 'di.document_id')
                    ->where('ds.company_id', $companyId);
            })
            ->groupBy('di.document_id');

        $conversionFlags = DB::table('sales.commercial_documents as dconv')
            ->select([
                'dconv.company_id',
                DB::raw("COALESCE((dconv.metadata->>'source_document_id')::BIGINT, 0) as source_document_id"),
                DB::raw("MAX(CASE WHEN dconv.document_kind IN ('INVOICE', 'RECEIPT') AND dconv.status NOT IN ('VOID', 'CANCELED') THEN 1 ELSE 0 END) as has_tributary_conversion"),
                DB::raw("MAX(CASE WHEN dconv.document_kind = 'SALES_ORDER' AND dconv.status NOT IN ('VOID', 'CANCELED') THEN 1 ELSE 0 END) as has_order_conversion"),
            ])
            ->where('dconv.company_id', $companyId)
            ->whereRaw("COALESCE((dconv.metadata->>'source_document_id')::BIGINT, 0) > 0")
            ->groupBy('dconv.company_id', DB::raw("COALESCE((dconv.metadata->>'source_document_id')::BIGINT, 0)"));

        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->leftJoin('auth.users as u_creator', 'u_creator.id', '=', 'd.created_by')
            ->leftJoin('sales.document_kinds as dk_id', 'dk_id.id', '=', 'd.document_kind_id')
            ->leftJoin('sales.document_kinds as dk_legacy', function ($join) {
                $join->on(DB::raw('UPPER(dk_legacy.code)'), '=', DB::raw('UPPER(d.document_kind)'));
            })
            ->leftJoin('sales.commercial_documents as dsrc', function ($join) {
                $join->on('dsrc.company_id', '=', 'd.company_id')
                    ->on('dsrc.id', '=', DB::raw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)"));
            })
            ->leftJoin('auth.users as u_source', 'u_source.id', '=', 'dsrc.created_by')
            ->leftJoin('sales.daily_summaries as ds_decl', function ($join) {
                $join->on('ds_decl.company_id', '=', 'd.company_id')
                    ->on('ds_decl.id', '=', DB::raw("NULLIF((d.metadata->>'sunat_summary_id'), '')::BIGINT"));
            })
            ->leftJoin('sales.daily_summaries as ds_void', function ($join) {
                $join->on('ds_void.company_id', '=', 'd.company_id')
                    ->on('ds_void.id', '=', DB::raw("NULLIF((d.metadata->>'sunat_void_summary_id'), '')::BIGINT"));
            })
            ->leftJoinSub($itemDiscountTotals, 'doc_item_totals', function ($join) {
                $join->on('doc_item_totals.document_id', '=', 'd.id');
            })
            ->leftJoinSub($conversionFlags, 'doc_conversion_flags', function ($join) {
                $join->on('doc_conversion_flags.company_id', '=', 'd.company_id')
                    ->on('doc_conversion_flags.source_document_id', '=', 'd.id');
            })
            ->select([
                'd.id',
                'd.company_id',
                'd.branch_id',
                'd.created_by',
                'd.document_kind',
                'd.document_kind_id',
                DB::raw("COALESCE((d.metadata->>'conversion_origin'), '') as conversion_origin"),
                DB::raw("CASE
                    WHEN UPPER(COALESCE((d.metadata->>'conversion_origin'), '')) LIKE 'RESTAURANT%'
                        OR NULLIF(TRIM(COALESCE((d.metadata->>'restaurant_table_id'), '')), '') IS NOT NULL
                        OR NULLIF(TRIM(COALESCE((dsrc.metadata->>'restaurant_table_id'), '')), '') IS NOT NULL
                    THEN true
                    ELSE false
                END as has_restaurant_origin"),
                DB::raw("COALESCE(dk_id.label, dk_legacy.label, d.document_kind) as document_kind_label"),
                DB::raw("CASE
                    WHEN UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind)) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE'
                    WHEN UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind)) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE'
                    ELSE UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind))
                END as document_kind_base"),
                DB::raw("CASE
                    WHEN (
                        CASE
                            WHEN UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind)) LIKE 'CREDIT_NOTE_%' THEN 'CREDIT_NOTE'
                            WHEN UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind)) LIKE 'DEBIT_NOTE_%' THEN 'DEBIT_NOTE'
                            ELSE UPPER(COALESCE(dk_id.code, dk_legacy.code, d.document_kind))
                        END
                    ) IN ('INVOICE','RECEIPT','CREDIT_NOTE','DEBIT_NOTE') THEN true ELSE false
                END as is_tributary_document"),
                'd.series',
                'd.number',
                'd.issue_at',
                'd.created_at',
                'd.status',
                DB::raw("CASE d.status
                    WHEN 'DRAFT'    THEN 'Borrador'
                    WHEN 'APPROVED' THEN 'Aprobado'
                    WHEN 'ISSUED'   THEN 'Emitido'
                    WHEN 'VOID'     THEN 'Anulado'
                    WHEN 'CANCELED' THEN 'Cancelado'
                    ELSE d.status END as status_label"),
                'd.external_status',
                DB::raw("COALESCE((d.metadata->>'sunat_status'), '') as sunat_status"),
                DB::raw("COALESCE((d.metadata->>'sunat_void_status'), '') as sunat_void_status"),
                DB::raw("NULLIF((d.metadata->>'sunat_summary_id'), '')::BIGINT as sunat_summary_id"),
                DB::raw("NULLIF((d.metadata->>'sunat_void_summary_id'), '')::BIGINT as sunat_void_summary_id"),
                DB::raw('ds_decl.status as declaration_summary_status'),
                DB::raw('ds_void.status as cancellation_summary_status'),
                'd.total',
                'd.balance_due',
                DB::raw('COALESCE(d.discount_total, 0) as global_discount_total'),
                DB::raw('COALESCE(doc_item_totals.item_discount_total, 0) as item_discount_total'),
                DB::raw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) as source_document_id"),
                DB::raw('dsrc.document_kind as source_document_kind'),
                DB::raw("COALESCE(
                    CASE
                        WHEN COALESCE((d.metadata->>'origin_seller_user_id'), '') ~ '^[0-9]+$' THEN (d.metadata->>'origin_seller_user_id')::BIGINT
                        ELSE NULL
                    END,
                    dsrc.created_by
                ) as origin_seller_user_id"),
                DB::raw("COALESCE(
                    NULLIF(TRIM(COALESCE((d.metadata->>'origin_seller_user_name'), '')), ''),
                    TRIM(COALESCE(CONCAT(COALESCE(u_source.first_name, ''), ' ', COALESCE(u_source.last_name, '')), ''))
                ) as origin_seller_user_name"),
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                DB::raw('COALESCE(doc_conversion_flags.has_tributary_conversion, 0) > 0 as has_tributary_conversion'),
                DB::raw('COALESCE(doc_conversion_flags.has_order_conversion, 0) > 0 as has_order_conversion'),
                DB::raw('doc_item_totals.document_id IS NOT NULL as has_items'),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.customer_vehicle_id AS TEXT)), ''), (d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId')), '')::BIGINT as customer_vehicle_id"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_plate_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot')), '') as vehicle_plate_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_brand_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_brand'), (d.metadata->>'vehicleBrand')), '') as vehicle_brand_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_model_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_model'), (d.metadata->>'vehicleModel')), '') as vehicle_model_snapshot"),
                DB::raw("TRIM(COALESCE(CONCAT(COALESCE(u_creator.first_name, ''), ' ', COALESCE(u_creator.last_name, '')), '')) as created_by_user_name"),
            ])
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        $total = (int) $countQuery->count('d.id');
        $lastPage = (int) max(1, ceil($total / $limit));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $rows = $query
            ->orderByRaw('COALESCE(d.created_at, d.issue_at) DESC')
            ->orderBy('d.id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    public function listCommercialDocumentsForExport(int $companyId, array $filters, int $max): Collection
    {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->leftJoin('auth.users as u_creator', 'u_creator.id', '=', 'd.created_by')
            ->select([
                'd.id',
                'd.created_by',
                'd.document_kind',
                'd.document_kind_id',
                DB::raw("COALESCE((SELECT dk.label FROM sales.document_kinds dk WHERE dk.id = d.document_kind_id LIMIT 1), (SELECT dk2.label FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(d.document_kind) LIMIT 1), d.document_kind) as document_kind_label"),
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                DB::raw("COALESCE((d.metadata->>'sunat_status'), '') as sunat_status"),
                DB::raw("COALESCE((d.metadata->>'sunat_void_status'), '') as sunat_void_status"),
                DB::raw("CASE d.status
                    WHEN 'DRAFT'    THEN 'Borrador'
                    WHEN 'APPROVED' THEN 'Aprobado'
                    WHEN 'ISSUED'   THEN 'Emitido'
                    WHEN 'VOID'     THEN 'Anulado'
                    WHEN 'CANCELED' THEN 'Cancelado'
                    ELSE d.status END as status_label"),
                'd.subtotal',
                'd.tax_total',
                'd.total',
                'd.balance_due',
                DB::raw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) as source_document_id"),
                DB::raw("(
                    SELECT dsrc.document_kind
                    FROM sales.commercial_documents dsrc
                    WHERE dsrc.company_id = d.company_id
                        AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                    LIMIT 1
                ) as source_document_kind"),
                DB::raw("COALESCE(
                    CASE
                        WHEN COALESCE((d.metadata->>'origin_seller_user_id'), '') ~ '^[0-9]+$' THEN (d.metadata->>'origin_seller_user_id')::BIGINT
                        ELSE NULL
                    END,
                    (
                        SELECT dsrc.created_by
                        FROM sales.commercial_documents dsrc
                        WHERE dsrc.company_id = d.company_id
                          AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                        LIMIT 1
                    )
                ) as origin_seller_user_id"),
                DB::raw("COALESCE(
                    NULLIF(TRIM(COALESCE((d.metadata->>'origin_seller_user_name'), '')), ''),
                    (
                        SELECT TRIM(COALESCE(CONCAT(COALESCE(u_src.first_name, ''), ' ', COALESCE(u_src.last_name, '')), ''))
                        FROM sales.commercial_documents dsrc
                        LEFT JOIN auth.users u_src ON u_src.id = dsrc.created_by
                        WHERE dsrc.company_id = d.company_id
                          AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                        LIMIT 1
                    )
                ) as origin_seller_user_name"),
                DB::raw("(
                    SELECT CONCAT(dsrc.series, '-', dsrc.number)
                    FROM sales.commercial_documents dsrc
                    WHERE dsrc.company_id = d.company_id
                        AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                    LIMIT 1
                ) as source_document_number"),
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.customer_vehicle_id AS TEXT)), ''), (d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId')), '')::BIGINT as customer_vehicle_id"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_plate_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot')), '') as vehicle_plate_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_brand_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_brand'), (d.metadata->>'vehicleBrand')), '') as vehicle_brand_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_model_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_model'), (d.metadata->>'vehicleModel')), '') as vehicle_model_snapshot"),
                DB::raw("TRIM(COALESCE(CONCAT(COALESCE(u_creator.first_name, ''), ' ', COALESCE(u_creator.last_name, '')), '')) as created_by_user_name"),
            ])
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        return $query
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->limit($max)
            ->get();
    }

    public function listCommercialDocumentProductsForExport(int $companyId, array $filters, int $max): Collection
    {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'd.payment_method_id')
            ->leftJoin('auth.users as u_creator', 'u_creator.id', '=', 'd.created_by')
            ->where('d.company_id', $companyId);

        $this->applyCommercialDocumentFilters($query, $filters);

        return $query
            ->join('sales.commercial_document_items as di', 'di.document_id', '=', 'd.id')
            ->leftJoin('inventory.products as p', function ($join) {
                $join->on('p.id', '=', 'di.product_id');
            })
            ->leftJoin('core.units as u', 'u.id', '=', 'di.unit_id')
            ->select([
                'd.id',
                'd.created_by',
                'd.document_kind',
                DB::raw("COALESCE((SELECT dk.label FROM sales.document_kinds dk WHERE dk.id = d.document_kind_id LIMIT 1), (SELECT dk2.label FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(d.document_kind) LIMIT 1), d.document_kind) as document_kind_label"),
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                DB::raw("COALESCE((d.metadata->>'sunat_status'), '') as sunat_status"),
                DB::raw("COALESCE((d.metadata->>'sunat_void_status'), '') as sunat_void_status"),
                DB::raw("CASE d.status
                    WHEN 'DRAFT'    THEN 'Borrador'
                    WHEN 'APPROVED' THEN 'Aprobado'
                    WHEN 'ISSUED'   THEN 'Emitido'
                    WHEN 'VOID'     THEN 'Anulado'
                    WHEN 'CANCELED' THEN 'Cancelado'
                    ELSE d.status END as status_label"),
                DB::raw("COALESCE(c.legal_name, CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) as customer_name"),
                DB::raw("COALESCE(pm.name, 'Sin metodo de pago') as payment_method_name"),
                DB::raw("COALESCE(
                    NULLIF(TRIM(COALESCE((d.metadata->>'origin_seller_user_name'), '')), ''),
                    (
                        SELECT TRIM(COALESCE(CONCAT(COALESCE(u_src.first_name, ''), ' ', COALESCE(u_src.last_name, '')), ''))
                        FROM sales.commercial_documents dsrc
                        LEFT JOIN auth.users u_src ON u_src.id = dsrc.created_by
                        WHERE dsrc.company_id = d.company_id
                          AND dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)
                        LIMIT 1
                    )
                ) as origin_seller_user_name"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.customer_vehicle_id AS TEXT)), ''), (d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId')), '')::BIGINT as customer_vehicle_id"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_plate_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot')), '') as vehicle_plate_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_brand_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_brand'), (d.metadata->>'vehicleBrand')), '') as vehicle_brand_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(d.vehicle_model_snapshot AS TEXT)), ''), (d.metadata->>'vehicle_model'), (d.metadata->>'vehicleModel')), '') as vehicle_model_snapshot"),
                DB::raw("TRIM(COALESCE(CONCAT(COALESCE(u_creator.first_name, ''), ' ', COALESCE(u_creator.last_name, '')), '')) as created_by_user_name"),
                'di.product_id',
                DB::raw("COALESCE(di.description, p.name, 'SIN DESCRIPCION') as product_description"),
                DB::raw("COALESCE(u.code, '-') as unit_code"),
                'di.qty',
                'di.unit_price',
                'di.total as line_total',
            ])
            ->orderBy('d.issue_at', 'desc')
            ->orderBy('d.id', 'desc')
            ->orderBy('di.line_no')
            ->limit($max)
            ->get();
    }

    public function registerCashIncomeFromDocument(
        int $companyId,
        ?int $branchId,
        int $cashRegisterId,
        int $documentId,
        string $documentKind,
        string $series,
        int $number,
        float $paidTotal,
        int $userId,
        ?int $paymentMethodId
    ): void {
        if (!$this->tableExistsBySchemaAndName('sales', 'cash_sessions') || !$this->tableExistsBySchemaAndName('sales', 'cash_movements')) {
            return;
        }

        $session = DB::table('sales.cash_sessions')
            ->where('company_id', $companyId)
            ->where('cash_register_id', $cashRegisterId)
            ->where('status', 'OPEN')
            ->orderByDesc('opened_at')
            ->first();

        if (!$session) {
            return;
        }

        $label = [
            'INVOICE' => 'Factura',
            'RECEIPT' => 'Boleta',
            'CREDIT_NOTE' => 'Nota Credito',
            'DEBIT_NOTE' => 'Nota Debito',
            'QUOTATION' => 'Cotizacion',
            'SALES_ORDER' => 'Pedido',
        ][$documentKind] ?? $documentKind;

        $description = 'Cobro doc ' . $label . ' ' . $series . '-' . $number;

        DB::table('sales.cash_movements')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'cash_register_id' => $cashRegisterId,
            'cash_session_id' => (int) $session->id,
            'movement_type' => 'INCOME',
            'payment_method_id' => $paymentMethodId,
            'amount' => round($paidTotal, 4),
            'description' => $description,
            'notes' => $description,
            'ref_type' => 'COMMERCIAL_DOCUMENT',
            'ref_id' => $documentId,
            'created_by' => $userId,
            'user_id' => $userId,
            'movement_at' => now(),
            'created_at' => now(),
        ]);

        $totalIn = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        DB::table('sales.cash_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'expected_balance' => round((float) $session->opening_balance + $totalIn - $totalOut, 4),
            ]);
    }

    public function resolveTaxCategoriesRows(int $companyId): array
    {
        $sourceTable = null;

        foreach (['core.tax_categories', 'sales.tax_categories', 'appcfg.tax_categories'] as $candidate) {
            [$schema, $table] = $this->splitQualifiedTable($candidate);
            if ($this->tableExistsBySchemaAndName($schema, $table)) {
                $sourceTable = $candidate;
                break;
            }
        }

        if (!$sourceTable) {
            return [];
        }

        $columns = $this->tableColumnsByQualifiedTable($sourceTable);
        $idColumn = $this->firstExistingColumn($columns, ['id']);
        $codeColumn = $this->firstExistingColumn($columns, ['code', 'sunat_code', 'tax_code']);
        $labelColumn = $this->firstExistingColumn($columns, ['name', 'label', 'description']);
        $rateColumn = $this->firstExistingColumn($columns, ['rate_percent', 'rate', 'percentage', 'tax_rate']);
        $statusColumn = $this->firstExistingColumn($columns, ['status', 'is_enabled', 'enabled', 'active']);
        $companyColumn = $this->firstExistingColumn($columns, ['company_id']);

        $query = DB::table($sourceTable);

        if ($statusColumn) {
            if ($statusColumn === 'status') {
                $query->where($statusColumn, 1);
            } else {
                $query->where($statusColumn, true);
            }
        }

        if ($companyColumn) {
            $query->where(function (Builder $nested) use ($companyColumn, $companyId): void {
                $nested->where($companyColumn, $companyId)
                    ->orWhereNull($companyColumn);
            });
        }

        return $query->get()->map(function ($row) use ($idColumn, $codeColumn, $labelColumn, $rateColumn) {
            $id = $idColumn ? (int) ($row->{$idColumn} ?? 0) : 0;
            $code = $codeColumn ? (string) ($row->{$codeColumn} ?? '') : '';
            $label = $labelColumn ? (string) ($row->{$labelColumn} ?? '') : '';
            $rate = $rateColumn ? (float) ($row->{$rateColumn} ?? 0) : 0.0;

            if ($label === '') {
                $label = $code !== '' ? $code : ('IGV #' . $id);
            }

            return [
                'id' => $id,
                'code' => $code,
                'label' => $label,
                'rate_percent' => round($rate, 4),
            ];
        })->filter(function ($row) {
            return $row['id'] > 0;
        })->values()->all();
    }

    public function resolveDocumentNoteReasonsRows(string $normalizedKind): array
    {
        $targetTable = $normalizedKind === 'DEBIT_NOTE'
            ? 'master.debit_note_reasons'
            : 'master.credit_note_reasons';

        [$schema, $table] = $this->splitQualifiedTable($targetTable);
        if (!$this->tableExistsBySchemaAndName($schema, $table)) {
            return [];
        }

        $columns = $this->tableColumnsByQualifiedTable($targetTable);
        $idColumn = $this->firstExistingColumn($columns, ['id']);
        $codeColumn = $this->firstExistingColumn($columns, ['code']);
        $descriptionColumn = $this->firstExistingColumn($columns, ['description', 'name', 'label']);
        $deletedColumn = $this->firstExistingColumn($columns, ['is_deleted', 'deleted']);
        $statusColumn = $this->firstExistingColumn($columns, ['status', 'is_enabled', 'enabled', 'active']);

        $query = DB::table($targetTable);

        if ($deletedColumn) {
            $query->where(function (Builder $nested) use ($deletedColumn): void {
                $nested->whereNull($deletedColumn)
                    ->orWhere($deletedColumn, false)
                    ->orWhere($deletedColumn, 0);
            });
        }

        if ($statusColumn) {
            if ($statusColumn === 'status') {
                $query->whereIn($statusColumn, [1, 2]);
            } else {
                $query->where(function (Builder $nested) use ($statusColumn): void {
                    $nested->where($statusColumn, true)
                        ->orWhere($statusColumn, 1)
                        ->orWhere($statusColumn, '1');
                });
            }
        }

        return $query->get()->map(function ($row) use ($idColumn, $codeColumn, $descriptionColumn) {
            return [
                'id' => $idColumn ? (int) ($row->{$idColumn} ?? 0) : 0,
                'code' => $codeColumn ? (string) ($row->{$codeColumn} ?? '') : '',
                'description' => $descriptionColumn ? (string) ($row->{$descriptionColumn} ?? '') : '',
            ];
        })->filter(function ($row) {
            return $row['id'] > 0 && trim($row['code']) !== '';
        })->sortBy(function ($row) {
            return $row['code'];
        })->values()->all();
    }

    public function resolveCompanyBankAccountsRaw(int $companyId): mixed
    {
        if (!$this->tableExistsBySchemaAndName('core', 'company_settings')) {
            return null;
        }

        $row = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('bank_accounts')
            ->first();

        return $row ? $row->bank_accounts : null;
    }

    public function findVerticalFeatureOverride(int $companyId, int $verticalId, string $featureCode): ?\App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO
    {
        $toggle = DB::table('appcfg.company_vertical_feature_overrides')
            ->where('company_id', $companyId)
            ->where('vertical_id', $verticalId)
            ->whereRaw('UPPER(feature_code) = ?', [strtoupper(trim($featureCode))])
            ->first(['is_enabled', 'config']);

        return $toggle ? \App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO::fromRow($toggle) : null;
    }

    public function findVerticalFeatureTemplate(int $verticalId, string $featureCode): ?\App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO
    {
        $toggle = DB::table('appcfg.vertical_feature_templates')
            ->where('vertical_id', $verticalId)
            ->whereRaw('UPPER(feature_code) = ?', [strtoupper(trim($featureCode))])
            ->first(['is_enabled', 'config']);

        return $toggle ? \App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO::fromRow($toggle) : null;
    }

    public function loadVerticalFeatureOverrides(int $companyId, int $verticalId): Collection
    {
        $cacheKey = sprintf('sales_lookup:vertical_feature_overrides:%d:%d', $companyId, $verticalId);

        return Cache::remember($cacheKey, self::VERTICAL_FEATURE_LOOKUP_CACHE_TTL_SECONDS, function () use ($companyId, $verticalId) {
            return DB::table('appcfg.company_vertical_feature_overrides')
                ->where('company_id', $companyId)
                ->where('vertical_id', $verticalId)
                ->get(['feature_code', 'is_enabled', 'config']);
        });
    }

    public function loadVerticalFeatureTemplates(int $verticalId): Collection
    {
        $cacheKey = sprintf('sales_lookup:vertical_feature_templates:%d', $verticalId);

        return Cache::remember($cacheKey, self::VERTICAL_FEATURE_LOOKUP_CACHE_TTL_SECONDS, function () use ($verticalId) {
            return DB::table('appcfg.vertical_feature_templates')
                ->where('vertical_id', $verticalId)
                ->get(['feature_code', 'is_enabled', 'config']);
        });
    }

    public function resolveActiveCompanyVertical(int $companyId): ?array
    {
        if (!$this->tableExistsBySchemaAndName('appcfg', 'verticals') || !$this->tableExistsBySchemaAndName('appcfg', 'company_verticals')) {
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
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ];
    }

    public function resolveDetractionServiceCodes(): array
    {
        if (!$this->tableExistsBySchemaAndName('master', 'detraccion_service_codes')) {
            return [];
        }

        return DB::table('master.detraccion_service_codes')
            ->select('id', 'code', 'name', 'rate_percent')
            ->where('is_active', 1)
            ->orderBy('code')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'rate_percent' => (float) $row->rate_percent,
                ];
            })
            ->values()
            ->all();
    }

    public function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);
        return $this->tableExistsBySchemaAndName($schema, $table);
    }

    public function tableColumns(string $qualifiedTable): array
    {
        return $this->tableColumnsByQualifiedTable($qualifiedTable);
    }

    public function loadCompanyFeatureToggles(int $companyId): Collection
    {
        return DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->get(['feature_code', 'is_enabled', 'config']);
    }

    public function loadBranchFeatureToggles(int $companyId, int $branchId): Collection
    {
        return DB::table('appcfg.branch_feature_toggles')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->get(['feature_code', 'is_enabled', 'config']);
    }

    public function enabledUnits(int $companyId): Collection
    {
        return DB::table('core.units as u')
            ->join('appcfg.company_units as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select('u.id', 'u.code', 'u.sunat_uom_code', 'u.name')
            ->where('cu.is_enabled', true)
            ->orderBy('u.name')
            ->get();
    }

    public function ensureCustomersPhoneColumn(): void
    {
        // Runtime DDL in hot paths causes lock/contention spikes in production.
        // Phone column must be provisioned by migrations.
        return;
    }

    public function fetchCustomerIdentityForSalesValidation(int $companyId, int $customerId): ?\App\Application\DTOs\Sales\SalesCustomerIdentityDTO
    {
        $customer = DB::table('sales.customers as c')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->select([
                'c.id',
                'c.doc_type',
                'c.doc_number',
                'ct.sunat_code as customer_type_sunat_code',
            ])
            ->where('c.company_id', $companyId)
            ->where('c.id', $customerId)
            ->first();

            return $customer ? \App\Application\DTOs\Sales\SalesCustomerIdentityDTO::fromRow($customer) : null;
    }

    public function resolveFallbackPaymentMethodId(int $companyId): ?int
    {
        if (!$this->tableExistsBySchemaAndName('master', 'payment_types')) {
            return null;
        }

        $columns = $this->tableColumnsByQualifiedTable('master.payment_types');
        $hasCompanyId = in_array('company_id', $columns, true);
        $hasStatus = in_array('status', $columns, true);

        $query = DB::table('master.payment_types');

        if ($hasStatus) {
            $query->where('status', 1);
        }

        if ($hasCompanyId) {
            $query->where(function (Builder $q) use ($companyId): void {
                $q->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            });

            $query->orderByRaw('CASE WHEN company_id = ? THEN 0 WHEN company_id IS NULL THEN 1 ELSE 2 END', [$companyId]);
        }

        $row = $query
            ->orderByRaw("CASE
                WHEN UPPER(COALESCE(name, '')) LIKE '%EFECTIV%' THEN 0
                WHEN UPPER(COALESCE(name, '')) LIKE '%CONTADO%' THEN 1
                WHEN UPPER(COALESCE(name, '')) LIKE '%CASH%' THEN 2
                ELSE 9
            END")
            ->orderBy('id')
            ->select('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    public function inventorySettingsForCompany(int $companyId): ?\App\Application\DTOs\Inventory\InventorySettingsDTO
    {
        $settings = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        return $settings ? \App\Application\DTOs\Inventory\InventorySettingsDTO::fromRow($settings) : null;
    }

    public function listLotsForCompany(int $companyId, ?int $productId = null, ?int $warehouseId = null, int $limit = 300): Collection
    {
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

        if ($productId !== null) {
            $query->where('pl.product_id', $productId);
        }

        if ($warehouseId !== null) {
            $query->where('pl.warehouse_id', $warehouseId);
        }

        return $query->limit($limit)->get();
    }

    public function companyProductExists(int $companyId, int $productId): bool
    {
        return DB::table('inventory.products')
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function companyWarehouseExists(int $companyId, int $warehouseId): bool
    {
        return DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function createLot(int $companyId, int $userId, array $payload): int
    {
        return (int) DB::table('inventory.product_lots')->insertGetId([
            'company_id' => $companyId,
            'warehouse_id' => (int) $payload['warehouse_id'],
            'product_id' => (int) $payload['product_id'],
            'lot_code' => strtoupper(trim((string) $payload['lot_code'])),
            'manufacture_at' => $payload['manufacture_at'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,
            'unit_cost' => $payload['unit_cost'] ?? null,
            'supplier_reference' => $payload['supplier_reference'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    public function buildDashboardData(int $companyId, bool $includePosStations): array
    {
        $warehouses = DB::table('inventory.warehouses')
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'address', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $cashRegisters = DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'warehouse_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $stations = $includePosStations
            ? DB::table('appcfg.pos_stations as ps')
                ->join('sales.cash_registers as cr', 'cr.id', '=', 'ps.cash_register_id')
                ->select([
                    'ps.id',
                    'ps.company_id',
                    'ps.cash_register_id',
                    'ps.code',
                    'ps.name',
                    'ps.device_id',
                    'ps.device_name',
                    'ps.status',
                    'cr.branch_id',
                    'cr.warehouse_id',
                    'cr.code as cash_register_code',
                    'cr.name as cash_register_name',
                ])
                ->where('ps.company_id', $companyId)
                ->orderBy('ps.name')
                ->get()
            : collect();

        $paymentMethods = DB::table('master.payment_types')
            ->select([
                'id',
                DB::raw("COALESCE(NULLIF(TRIM(comment), ''), CONCAT('PM', id::text)) as code"),
                'name',
                DB::raw('CASE WHEN COALESCE(is_active, 0) = 1 OR COALESCE(status, 0) IN (1, 2) THEN 1 ELSE 0 END as status'),
            ])
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

        $inventorySettingsRow = DB::table('inventory.inventory_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$inventorySettingsRow) {
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
            $inventorySettings = [
                'company_id' => $inventorySettingsRow->company_id,
                'complexity_mode' => $inventorySettingsRow->complexity_mode ?? 'BASIC',
                'inventory_mode' => $inventorySettingsRow->inventory_mode ?? 'KARDEX_SIMPLE',
                'lot_outflow_strategy' => $inventorySettingsRow->lot_outflow_strategy ?? 'MANUAL',
                'enable_inventory_pro' => (bool) $inventorySettingsRow->enable_inventory_pro,
                'enable_lot_tracking' => (bool) $inventorySettingsRow->enable_lot_tracking,
                'enable_expiry_tracking' => (bool) $inventorySettingsRow->enable_expiry_tracking,
                'enable_advanced_reporting' => (bool) $inventorySettingsRow->enable_advanced_reporting,
                'enable_graphical_dashboard' => (bool) $inventorySettingsRow->enable_graphical_dashboard,
                'enable_location_control' => (bool) $inventorySettingsRow->enable_location_control,
                'allow_negative_stock' => (bool) $inventorySettingsRow->allow_negative_stock,
                'enforce_lot_for_tracked' => (bool) $inventorySettingsRow->enforce_lot_for_tracked,
            ];
        }

        return [
            'warehouses' => $warehouses,
            'cash_registers' => $cashRegisters,
            'pos_stations' => $stations,
            'payment_methods' => $paymentMethods,
            'series' => $series,
            'price_tiers' => $priceTiers,
            'lots' => $lots,
            'inventory_settings' => $inventorySettings,
        ];
    }

    public function companyUserExists(int $companyId, int $userId): bool
    {
        return DB::table('auth.users')
            ->where('id', $userId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function warehouseExists(int $companyId, int $warehouseId): bool
    {
        return DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function countEnabledWarehouses(int $companyId): int
    {
        return (int) DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->count();
    }

    public function createWarehouse(int $companyId, array $payload): int
    {
        return (int) DB::table('inventory.warehouses')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'code' => strtoupper(trim((string) $payload['code'])),
            'name' => trim((string) $payload['name']),
            'address' => $payload['address'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
        ]);
    }

    public function updateWarehouse(int $companyId, int $warehouseId, array $updates): void
    {
        DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->update($updates);
    }

    public function listCashRegistersForCompany(int $companyId): Collection
    {
        return DB::table('sales.cash_registers')
            ->select('id', 'company_id', 'branch_id', 'warehouse_id', 'code', 'name', 'status')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();
    }

    public function posStationsTableExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'appcfg')
            ->where('table_name', 'pos_stations')
            ->exists();
    }

    public function listPosStationsForCompany(int $companyId): Collection
    {
        return DB::table('appcfg.pos_stations as ps')
            ->join('sales.cash_registers as cr', 'cr.id', '=', 'ps.cash_register_id')
            ->select([
                'ps.id',
                'ps.company_id',
                'ps.cash_register_id',
                'ps.code',
                'ps.name',
                'ps.device_id',
                'ps.device_name',
                'ps.status',
                'cr.branch_id',
                'cr.warehouse_id',
                'cr.code as cash_register_code',
                'cr.name as cash_register_name',
            ])
            ->where('ps.company_id', $companyId)
            ->orderBy('ps.name')
            ->get();
    }

    public function activeWarehouseExists(int $companyId, int $warehouseId): bool
    {
        return DB::table('inventory.warehouses')
            ->where('id', $warehouseId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    public function cashRegisterExists(int $companyId, int $cashRegisterId): bool
    {
        return DB::table('sales.cash_registers')
            ->where('id', $cashRegisterId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function activeCashRegisterExists(int $companyId, int $cashRegisterId): bool
    {
        return DB::table('sales.cash_registers')
            ->where('id', $cashRegisterId)
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->exists();
    }

    public function countEnabledCashRegisters(int $companyId): int
    {
        return (int) DB::table('sales.cash_registers')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->count();
    }

    public function countEnabledCashRegistersForWarehouse(int $companyId, int $warehouseId): int
    {
        return (int) DB::table('sales.cash_registers')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 1)
            ->count();
    }

    public function createCashRegister(int $companyId, array $payload, int $warehouseId): int
    {
        return (int) DB::table('sales.cash_registers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'warehouse_id' => $warehouseId,
            'code' => strtoupper(trim((string) $payload['code'])),
            'name' => trim((string) $payload['name']),
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
        ]);
    }

    public function updateCashRegister(int $companyId, int $cashRegisterId, array $updates): void
    {
        DB::table('sales.cash_registers')
            ->where('id', $cashRegisterId)
            ->where('company_id', $companyId)
            ->update($updates);
    }

    public function posStationExists(int $companyId, int $stationId): bool
    {
        return DB::table('appcfg.pos_stations')
            ->where('id', $stationId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function posStationCodeExists(int $companyId, string $normalizedCode, ?int $excludeId = null): bool
    {
        $query = DB::table('appcfg.pos_stations')
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(code) = ?', [$normalizedCode]);

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        return $query->exists();
    }

    public function findPosStationDeviceConflict(int $companyId, string $normalizedDeviceId, ?int $excludeId = null): ?\App\Application\DTOs\Sales\PosStationConflictDTO
    {
        $query = DB::table('appcfg.pos_stations')
            ->select(['id', 'company_id', 'code'])
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(device_id)) = ?', [strtolower($normalizedDeviceId)]);

        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        $conflict = $query->first();

        return $conflict ? \App\Application\DTOs\Sales\PosStationConflictDTO::fromRow($conflict) : null;
    }

    public function createPosStation(int $companyId, int $cashRegisterId, array $payload, string $normalizedCode, string $normalizedDeviceId): int
    {
        return (int) DB::table('appcfg.pos_stations')->insertGetId([
            'company_id' => $companyId,
            'cash_register_id' => $cashRegisterId,
            'code' => $normalizedCode,
            'name' => trim((string) $payload['name']),
            'device_id' => $normalizedDeviceId,
            'device_name' => !empty($payload['device_name']) ? trim((string) $payload['device_name']) : null,
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function updatePosStation(int $companyId, int $stationId, array $updates): void
    {
        DB::table('appcfg.pos_stations')
            ->where('id', $stationId)
            ->where('company_id', $companyId)
            ->update($updates);
    }

    public function ensureCompanyRoleProfilesTable(): void
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

    public function buildAccessControlData(int $companyId): array
    {
        $this->ensureCompanyRoleProfilesTable();

        $functionalProfiles = $this->listCompanyFunctionalProfiles($companyId);
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
                    $permission = $permissions->get($module->code);

                    return [
                        'module_id' => (int) $module->id,
                        'module_code' => $module->code,
                        'module_name' => $module->name,
                        'can_view' => (bool) ($permission->can_view ?? false),
                        'can_create' => (bool) ($permission->can_create ?? false),
                        'can_update' => (bool) ($permission->can_update ?? false),
                        'can_delete' => (bool) ($permission->can_delete ?? false),
                        'can_export' => (bool) ($permission->can_export ?? false),
                        'can_approve' => (bool) ($permission->can_approve ?? false),
                    ];
                })->values();

                return [
                    'id' => (int) $role->id,
                    'company_id' => (int) $role->company_id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'status' => (int) $role->status,
                    'functional_profile' => $roleProfiles->get($role->id),
                    'permissions' => $modulePermissions,
                ];
            })
            ->values();

        $users = DB::table('auth.users as u')
            ->leftJoin('auth.user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('auth.roles as r', function ($join) use ($companyId): void {
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
                'u.preferred_warehouse_id',
                'u.preferred_cash_register_id',
                DB::raw('MIN(r.id) as role_id'),
                DB::raw('MIN(r.code) as role_code'),
            ])
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->groupBy('u.id', 'u.branch_id', 'u.username', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.status', 'u.preferred_warehouse_id', 'u.preferred_cash_register_id')
            ->orderBy('u.username')
            ->get()
            ->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                    'username' => $row->username,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'status' => (int) $row->status,
                    'preferred_warehouse_id' => $row->preferred_warehouse_id !== null ? (int) $row->preferred_warehouse_id : null,
                    'preferred_cash_register_id' => $row->preferred_cash_register_id !== null ? (int) $row->preferred_cash_register_id : null,
                    'role_id' => $row->role_id !== null ? (int) $row->role_id : null,
                    'role_code' => $row->role_code,
                ];
            })
            ->values();

        return [
            'modules' => $modules,
            'roles' => $roles,
            'users' => $users,
            'functional_profiles' => $functionalProfiles,
        ];
    }

    public function ensureCompanyFunctionalProfilesTable(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_functional_profiles (
                company_id BIGINT NOT NULL,
                code VARCHAR(40) NOT NULL,
                label VARCHAR(120) NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 100,
                updated_by BIGINT NULL,
                updated_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                PRIMARY KEY (company_id, code)
            )'
        );
    }

    public function listCompanyFunctionalProfiles(int $companyId): Collection
    {
        $this->ensureCompanyFunctionalProfilesTable();

        $hasProfiles = DB::table('appcfg.company_functional_profiles')
            ->where('company_id', $companyId)
            ->exists();

        if (!$hasProfiles) {
            $seedRows = collect([
                ['code' => 'GENERAL', 'label' => 'General', 'sort_order' => 10],
                ['code' => 'SELLER', 'label' => 'Vendedor', 'sort_order' => 20],
                ['code' => 'CASHIER', 'label' => 'Cajero', 'sort_order' => 30],
            ])->map(function (array $item) use ($companyId): array {
                return [
                    'company_id' => $companyId,
                    'code' => (string) $item['code'],
                    'label' => (string) $item['label'],
                    'status' => 1,
                    'sort_order' => (int) $item['sort_order'],
                    'updated_by' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            })->all();

            DB::table('appcfg.company_functional_profiles')->insert($seedRows);
        }

        return DB::table('appcfg.company_functional_profiles')
            ->select('code', 'label', 'status', 'sort_order')
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(function ($row): array {
                return [
                    'code' => (string) $row->code,
                    'label' => (string) $row->label,
                    'status' => (int) $row->status,
                    'sort_order' => (int) ($row->sort_order ?? 100),
                ];
            })
            ->values();
    }

    public function companyFunctionalProfileExists(int $companyId, string $code): bool
    {
        return DB::table('appcfg.company_functional_profiles')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->exists();
    }

    public function insertCompanyFunctionalProfile(int $companyId, int $updatedBy, array $payload): void
    {
        DB::table('appcfg.company_functional_profiles')->insert([
            'company_id' => $companyId,
            'code' => $payload['code'],
            'label' => $payload['label'],
            'status' => $payload['status'],
            'sort_order' => $payload['sort_order'],
            'updated_by' => $updatedBy,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function updateCompanyFunctionalProfile(int $companyId, string $code, array $updates, ?int $updatedBy): void
    {
        $updates['updated_by'] = $updatedBy;
        $updates['updated_at'] = now();

        DB::table('appcfg.company_functional_profiles')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->update($updates);
    }

    public function syncCompanyRoleFunctionalProfile(int $companyId, int $roleId, ?string $functionalProfile, ?int $updatedBy): void
    {
        DB::table('appcfg.company_role_profiles')->updateOrInsert(
            [
                'company_id' => $companyId,
                'role_id' => $roleId,
            ],
            [
                'functional_profile' => $functionalProfile,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]
        );
    }

    public function updateDocumentKindsBulk(int $companyId, int $authUserId, array $items): void
    {
        $this->ensureDocumentKindsTable();

        foreach ($items as $item) {
            $sourceCode = strtoupper(trim((string) ($item['original_code'] ?? $item['code'])));
            $targetCode = strtoupper(trim((string) $item['code']));

            $sourceExists = DB::table('sales.document_kinds')->where('code', $sourceCode)->exists();
            if (!$sourceExists) {
                throw new \RuntimeException('Document kind code not found: ' . $sourceCode);
            }

            if ($sourceCode !== $targetCode) {
                $targetExists = DB::table('sales.document_kinds')->where('code', $targetCode)->exists();
                if ($targetExists) {
                    throw new \RuntimeException('Document kind code already exists: ' . $targetCode);
                }

                DB::table('sales.document_kinds')
                    ->where('code', $sourceCode)
                    ->update([
                        'code' => $targetCode,
                        'updated_at' => now(),
                    ]);

                if ($this->tableExistsBySchemaAndName('sales', 'document_sequences')) {
                    DB::table('sales.document_sequences')
                        ->where('document_kind', $sourceCode)
                        ->update(['document_kind' => $targetCode]);
                }

                if ($this->tableExistsBySchemaAndName('sales', 'commercial_documents')) {
                    DB::table('sales.commercial_documents')
                        ->where('document_kind', $sourceCode)
                        ->update(['document_kind' => $targetCode]);
                }

                DB::table('appcfg.company_feature_toggles')
                    ->where('feature_code', 'DOC_KIND_' . $sourceCode)
                    ->update(['feature_code' => 'DOC_KIND_' . $targetCode]);
            }

            if (array_key_exists('label', $item) && trim((string) $item['label']) !== '') {
                DB::table('sales.document_kinds')
                    ->where('code', $targetCode)
                    ->update([
                        'label' => trim((string) $item['label']),
                        'is_enabled' => (bool) $item['is_enabled'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('sales.document_kinds')
                    ->where('code', $targetCode)
                    ->update([
                        'is_enabled' => (bool) $item['is_enabled'],
                        'updated_at' => now(),
                    ]);
            }

            DB::table('appcfg.company_feature_toggles')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'feature_code' => 'DOC_KIND_' . $targetCode,
                ],
                [
                    'is_enabled' => (bool) $item['is_enabled'],
                    'config' => json_encode(['managed_by' => 'masters']),
                    'updated_by' => $authUserId,
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function createRole(int $companyId, string $code, string $name, int $status): int
    {
        return (int) DB::table('auth.roles')->insertGetId([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'status' => $status,
        ]);
    }

    public function updateRole(int $companyId, int $roleId, array $updates): void
    {
        DB::table('auth.roles')
            ->where('id', $roleId)
            ->where('company_id', $companyId)
            ->update($updates);
    }

    public function roleExists(int $companyId, int $roleId): bool
    {
        return DB::table('auth.roles')
            ->where('id', $roleId)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function syncRolePermissions(int $roleId, array $permissions): void
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

    public function createCompanyUser(int $companyId, array $payload): int
    {
        if (!empty($payload['branch_id']) && !$this->branchExists($companyId, (int) $payload['branch_id'])) {
            throw new \RuntimeException('Invalid branch scope');
        }

        if (!$this->roleExists($companyId, (int) $payload['role_id'])) {
            throw new \RuntimeException('Invalid role scope');
        }

        $branchId = array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null ? (int) $payload['branch_id'] : null;
        $preferredWarehouseId = array_key_exists('preferred_warehouse_id', $payload) && $payload['preferred_warehouse_id'] !== null ? (int) $payload['preferred_warehouse_id'] : null;
        $preferredCashRegisterId = array_key_exists('preferred_cash_register_id', $payload) && $payload['preferred_cash_register_id'] !== null ? (int) $payload['preferred_cash_register_id'] : null;

        $scopeError = $this->validateOperationalContextSelection($companyId, $branchId, $preferredWarehouseId, $preferredCashRegisterId);
        if ($scopeError !== null) {
            throw new \RuntimeException($scopeError);
        }

        $defaultContext = $this->resolveDefaultOperationalContext($companyId, $branchId);
        $resolvedWarehouseId = array_key_exists('preferred_warehouse_id', $payload) && $payload['preferred_warehouse_id'] !== null
            ? (int) $payload['preferred_warehouse_id']
            : $defaultContext['warehouse_id'];
        $resolvedCashRegisterId = array_key_exists('preferred_cash_register_id', $payload) && $payload['preferred_cash_register_id'] !== null
            ? (int) $payload['preferred_cash_register_id']
            : $defaultContext['cash_register_id'];

        $authUserColumns = $this->tableColumns('auth.users');
        $userRoleColumns = $this->tableColumns('auth.user_roles');

        $hasPreferredWarehouseColumn = in_array('preferred_warehouse_id', $authUserColumns, true);
        $hasPreferredCashRegisterColumn = in_array('preferred_cash_register_id', $authUserColumns, true);
        $hasUserRolesCreatedAtColumn = in_array('created_at', $userRoleColumns, true);
        $hasUserRolesUpdatedAtColumn = in_array('updated_at', $userRoleColumns, true);

        $username = trim((string) $payload['username']);
        $email = array_key_exists('email', $payload) && $payload['email'] !== null ? trim(strtolower((string) $payload['email'])) : null;

        $restorableUserId = null;
        $existingByUsername = DB::table('auth.users')
            ->select(['id', 'company_id', 'deleted_at'])
            ->whereRaw('LOWER(username) = ?', [strtolower($username)])
            ->first();

        if ($existingByUsername) {
            $sameCompany = (int) $existingByUsername->company_id === $companyId;
            $isSoftDeleted = $existingByUsername->deleted_at !== null;

            if ($sameCompany && $isSoftDeleted) {
                $restorableUserId = (int) $existingByUsername->id;
            } else {
                throw new \RuntimeException('El usuario ya existe.');
            }
        }

        if ($email !== null && $email !== '') {
            $existingByEmail = DB::table('auth.users')
                ->select(['id', 'company_id', 'deleted_at'])
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($existingByEmail) {
                $sameCompany = (int) $existingByEmail->company_id === $companyId;
                $isSoftDeleted = $existingByEmail->deleted_at !== null;
                $sameCandidate = $restorableUserId !== null && $restorableUserId === (int) $existingByEmail->id;

                if (!$sameCandidate) {
                    if ($sameCompany && $isSoftDeleted && $restorableUserId === null) {
                        $restorableUserId = (int) $existingByEmail->id;
                    } else {
                        throw new \RuntimeException('El correo ya existe.');
                    }
                }
            }
        }

        $userInsert = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'username' => $username,
            'password_hash' => Hash::make((string) $payload['password']),
            'first_name' => trim((string) $payload['first_name']),
            'last_name' => $payload['last_name'] ?? null,
            'email' => $email,
            'phone' => $payload['phone'] ?? null,
            'status' => (int) ($payload['status'] ?? 1),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($hasPreferredWarehouseColumn) {
            $userInsert['preferred_warehouse_id'] = $resolvedWarehouseId;
        }

        if ($hasPreferredCashRegisterColumn) {
            $userInsert['preferred_cash_register_id'] = $resolvedCashRegisterId;
        }

        return (int) DB::transaction(function () use ($userInsert, $payload, $restorableUserId, $hasUserRolesCreatedAtColumn, $hasUserRolesUpdatedAtColumn) {
            $roleInsert = [
                'user_id' => 0,
                'role_id' => (int) $payload['role_id'],
            ];

            if ($hasUserRolesCreatedAtColumn) {
                $roleInsert['created_at'] = now();
            }

            if ($hasUserRolesUpdatedAtColumn) {
                $roleInsert['updated_at'] = now();
            }

            if ($restorableUserId !== null) {
                $userUpdate = $userInsert;
                unset($userUpdate['created_at']);
                $userUpdate['deleted_at'] = null;

                DB::table('auth.users')
                    ->where('id', $restorableUserId)
                    ->update($userUpdate);

                DB::table('auth.user_roles')
                    ->where('user_id', $restorableUserId)
                    ->delete();

                $roleInsert['user_id'] = (int) $restorableUserId;
                DB::table('auth.user_roles')->insert($roleInsert);

                return (int) $restorableUserId;
            }

            $userId = DB::table('auth.users')->insertGetId($userInsert);
            $roleInsert['user_id'] = (int) $userId;
            DB::table('auth.user_roles')->insert($roleInsert);

            return (int) $userId;
        });
    }

    public function updateCompanyUser(int $companyId, int $id, array $payload): void
    {
        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null && !$this->branchExists($companyId, (int) $payload['branch_id'])) {
            throw new \RuntimeException('Invalid branch scope');
        }

        if (!$this->roleExists($companyId, (int) ($payload['role_id'] ?? 0)) && array_key_exists('role_id', $payload)) {
            throw new \RuntimeException('Invalid role scope');
        }

        $branchId = array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null ? (int) $payload['branch_id'] : null;
        $preferredWarehouseId = array_key_exists('preferred_warehouse_id', $payload) && $payload['preferred_warehouse_id'] !== null ? (int) $payload['preferred_warehouse_id'] : null;
        $preferredCashRegisterId = array_key_exists('preferred_cash_register_id', $payload) && $payload['preferred_cash_register_id'] !== null ? (int) $payload['preferred_cash_register_id'] : null;

        $scopeError = $this->validateOperationalContextSelection(
            $companyId,
            $branchId,
            array_key_exists('preferred_warehouse_id', $payload) ? $preferredWarehouseId : null,
            array_key_exists('preferred_cash_register_id', $payload) ? $preferredCashRegisterId : null
        );

        if ($scopeError !== null) {
            throw new \RuntimeException($scopeError);
        }

        $updates = ['updated_at' => now()];
        $authUserColumns = $this->tableColumns('auth.users');
        $hasPreferredWarehouseColumn = in_array('preferred_warehouse_id', $authUserColumns, true);
        $hasPreferredCashRegisterColumn = in_array('preferred_cash_register_id', $authUserColumns, true);

        foreach (['branch_id', 'first_name', 'last_name', 'email', 'phone', 'status'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        $effectiveBranchId = array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null ? (int) $payload['branch_id'] : null;

        if (array_key_exists('preferred_warehouse_id', $payload) || array_key_exists('preferred_cash_register_id', $payload)) {
            $needsDefault = (array_key_exists('preferred_warehouse_id', $payload) && $payload['preferred_warehouse_id'] === null)
                || (array_key_exists('preferred_cash_register_id', $payload) && $payload['preferred_cash_register_id'] === null);

            if ($needsDefault) {
                $defaultOperationalContext = $this->resolveDefaultOperationalContext($companyId, $effectiveBranchId);
            }

            if ($hasPreferredWarehouseColumn && array_key_exists('preferred_warehouse_id', $payload)) {
                $updates['preferred_warehouse_id'] = $payload['preferred_warehouse_id'] !== null
                    ? (int) $payload['preferred_warehouse_id']
                    : ($defaultOperationalContext['warehouse_id'] ?? null);
            }
            if ($hasPreferredCashRegisterColumn && array_key_exists('preferred_cash_register_id', $payload)) {
                $updates['preferred_cash_register_id'] = $payload['preferred_cash_register_id'] !== null
                    ? (int) $payload['preferred_cash_register_id']
                    : ($defaultOperationalContext['cash_register_id'] ?? null);
            }
        } elseif (array_key_exists('branch_id', $payload) && ($hasPreferredWarehouseColumn || $hasPreferredCashRegisterColumn)) {
            $defaultOperationalContext = $this->resolveDefaultOperationalContext($companyId, $effectiveBranchId);
            if ($hasPreferredWarehouseColumn) {
                $updates['preferred_warehouse_id'] = $defaultOperationalContext['warehouse_id'];
            }
            if ($hasPreferredCashRegisterColumn) {
                $updates['preferred_cash_register_id'] = $defaultOperationalContext['cash_register_id'];
            }
        }

        if (!empty($payload['password'])) {
            $updates['password_hash'] = Hash::make($payload['password']);
        }

        DB::table('auth.users')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($updates);

        if (array_key_exists('role_id', $payload)) {
            DB::table('auth.user_roles')->where('user_id', $id)->delete();
            DB::table('auth.user_roles')->insert([
                'user_id' => $id,
                'role_id' => (int) $payload['role_id'],
            ]);
        }
    }

    private function validateOperationalContextSelection(
        int $companyId,
        ?int $branchId,
        ?int $preferredWarehouseId,
        ?int $preferredCashRegisterId
    ): ?string {
        if ($preferredWarehouseId !== null) {
            $warehouseExists = DB::table('inventory.warehouses')
                ->where('company_id', $companyId)
                ->where('id', $preferredWarehouseId)
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->where(function ($nested) use ($branchId) {
                        $nested->where('branch_id', $branchId)
                            ->orWhereNull('branch_id');
                    });
                })
                ->exists();

            if (!$warehouseExists) {
                return 'Invalid warehouse scope';
            }
        }

        if ($preferredCashRegisterId !== null) {
            $cashRegisterExists = DB::table('sales.cash_registers')
                ->where('company_id', $companyId)
                ->where('id', $preferredCashRegisterId)
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->where(function ($nested) use ($branchId) {
                        $nested->where('branch_id', $branchId)
                            ->orWhereNull('branch_id');
                    });
                })
                ->when($preferredWarehouseId !== null, function ($query) use ($preferredWarehouseId) {
                    $query->where(function ($nested) use ($preferredWarehouseId) {
                        $nested->where('warehouse_id', $preferredWarehouseId)
                            ->orWhereNull('warehouse_id');
                    });
                })
                ->exists();

            if (!$cashRegisterExists) {
                return 'Invalid cash register scope';
            }
        }

        return null;
    }

    private function resolveDefaultOperationalContext(int $companyId, ?int $branchId): array
    {
        $warehouseId = DB::table('inventory.warehouses')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->orderByRaw($branchId !== null ? 'CASE WHEN branch_id = ? THEN 0 ELSE 1 END' : 'CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END', $branchId !== null ? [$branchId] : [])
            ->orderBy('name')
            ->value('id');

        $cashRegisterId = DB::table('sales.cash_registers')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->when($warehouseId !== null, function ($query) use ($warehouseId) {
                $query->where(function ($nested) use ($warehouseId) {
                    $nested->where('warehouse_id', (int) $warehouseId)
                        ->orWhereNull('warehouse_id');
                });
            })
            ->orderByRaw($warehouseId !== null ? 'CASE WHEN warehouse_id = ? THEN 0 ELSE 1 END' : 'CASE WHEN warehouse_id IS NULL THEN 0 ELSE 1 END', $warehouseId !== null ? [(int) $warehouseId] : [])
            ->orderBy('name')
            ->value('id');

        return [
            'warehouse_id' => $warehouseId !== null ? (int) $warehouseId : null,
            'cash_register_id' => $cashRegisterId !== null ? (int) $cashRegisterId : null,
        ];
    }

    public function resolveAuthRoleContext(int $userId, int $companyId): ?\App\Application\DTOs\Auth\AuthRoleContextDTO
    {
        $context = DB::table('auth.user_roles as ur')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('appcfg.company_role_profiles as crp', function ($join) use ($companyId): void {
                $join->on('crp.role_id', '=', 'r.id')
                    ->where('crp.company_id', '=', $companyId);
            })
            ->where('ur.user_id', $userId)
            ->where('r.company_id', $companyId)
            ->where('r.status', 1)
            ->orderBy('r.id')
            ->select('r.code as role_code', 'crp.functional_profile as role_profile')
            ->first();

            return $context ? \App\Application\DTOs\Auth\AuthRoleContextDTO::fromRow($context) : null;
    }

    public function findProductUomConversionFactor(int $companyId, int $productId, int $fromUnitId, int $toUnitId): ?float
    {
        $factor = DB::table('inventory.product_uom_conversions')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('from_unit_id', $fromUnitId)
            ->where('to_unit_id', $toUnitId)
            ->where('status', 1)
            ->value('conversion_factor');

        return $factor !== null ? (float) $factor : null;
    }

    public function findCurrentStockRow(int $companyId, int $warehouseId, int $productId): ?\App\Application\DTOs\Inventory\InventoryStockLevelDTO
    {
        $stock = DB::table('inventory.current_stock')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        return $stock ? \App\Application\DTOs\Inventory\InventoryStockLevelDTO::fromRow($stock) : null;
    }

    public function listCandidateOutboundLots(
        int $companyId,
        int $warehouseId,
        int $productId,
        string $strategy
    ): Collection {
        return DB::table('inventory.product_lots as pl')
            ->leftJoin('inventory.current_stock_by_lot as csl', function ($join) use ($companyId, $warehouseId, $productId): void {
                $join->on('csl.lot_id', '=', 'pl.id')
                    ->where('csl.company_id', '=', $companyId)
                    ->where('csl.warehouse_id', '=', $warehouseId)
                    ->where('csl.product_id', '=', $productId);
            })
            ->select([
                'pl.id',
                'pl.expires_at',
                'pl.received_at',
                DB::raw('COALESCE(csl.stock, 0) as stock'),
            ])
            ->where('pl.company_id', $companyId)
            ->where('pl.warehouse_id', $warehouseId)
            ->where('pl.product_id', $productId)
            ->where('pl.status', 1)
            ->orderByRaw($strategy === 'FEFO' ? 'CASE WHEN pl.expires_at IS NULL THEN 1 ELSE 0 END, pl.expires_at ASC, pl.received_at ASC, pl.id ASC' : 'pl.received_at ASC, pl.id ASC')
            ->get();
    }

    public function findCurrentStockByLotRow(int $companyId, int $warehouseId, int $productId, int $lotId): ?\App\Application\DTOs\Inventory\InventoryStockLevelDTO
    {
        $stock = DB::table('inventory.current_stock_by_lot')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('lot_id', $lotId)
            ->first();

        return $stock ? \App\Application\DTOs\Inventory\InventoryStockLevelDTO::fromRow($stock) : null;
    }

    public function findTaxBridgeDocumentForDebug(int $companyId, int $documentId): ?\App\Application\DTOs\Sales\TaxBridgeDebugDocumentDTO
    {
        $document = DB::table('sales.commercial_documents')
            ->select('id', 'company_id', 'branch_id')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->first();

        return $document ? \App\Application\DTOs\Sales\TaxBridgeDebugDocumentDTO::fromRow($document) : null;
    }

    private function applyCommercialDocumentFilters(Builder $query, array $filters): void
    {
        $branchId = $filters['branch_id'] ?? null;
        $warehouseId = $filters['warehouse_id'] ?? null;
        $cashRegisterId = $filters['cash_register_id'] ?? null;
        $sourceOrigin = strtoupper(trim((string) ($filters['source_origin'] ?? '')));
        $documentKind = $filters['document_kind'] ?? null;
        $documentKindId = $filters['document_kind_id'] ?? null;
        $status = $filters['status'] ?? null;
        $conversionState = $filters['conversion_state'] ?? null;
        $customer = trim((string) ($filters['customer'] ?? ''));
        $customerId = (int) ($filters['customer_id'] ?? 0);
        $vehicle = trim((string) ($filters['vehicle'] ?? ''));
        $customerVehicleId = $filters['customer_vehicle_id'] ?? null;
        $issueDateFrom = $filters['issue_date_from'] ?? null;
        $issueDateTo = $filters['issue_date_to'] ?? null;
        $series = trim((string) ($filters['series'] ?? ''));
        $number = trim((string) ($filters['number'] ?? ''));
        $sellerUserId = isset($filters['seller_user_id']) ? (int) $filters['seller_user_id'] : null;
        $workshopVehicleSearchEnabled = (bool) ($filters['workshop_vehicle_search_enabled'] ?? false);

        if ($sellerUserId !== null && $sellerUserId > 0) {
            $query->whereRaw("COALESCE(d.seller_user_id, CASE WHEN COALESCE((d.metadata->>'origin_seller_user_id'), '') ~ '^[0-9]+$' THEN (d.metadata->>'origin_seller_user_id')::BIGINT ELSE NULL END, d.created_by) = ?", [$sellerUserId]);
        }

        if ($branchId !== null && $branchId !== '') {
            $query->where('d.branch_id', (int) $branchId);
        }

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('d.warehouse_id', (int) $warehouseId);
        }

        if ($cashRegisterId !== null && $cashRegisterId !== '') {
            $query->whereRaw("COALESCE((d.metadata->>'cash_register_id')::BIGINT, 0) = ?", [(int) $cashRegisterId]);
        }

        if ($sourceOrigin === 'RESTAURANT') {
            $query->where(function (Builder $nested): void {
                $nested->whereRaw("UPPER(COALESCE((d.metadata->>'conversion_origin'), '')) LIKE 'RESTAURANT%'")
                    ->orWhereRaw("NULLIF(TRIM(COALESCE((d.metadata->>'restaurant_table_id'), '')), '') IS NOT NULL")
                    ->orWhereExists(function (Builder $sourceQuery): void {
                        $sourceQuery->select(DB::raw('1'))
                            ->from('sales.commercial_documents as dsrc')
                            ->whereColumn('dsrc.company_id', 'd.company_id')
                            ->whereRaw("dsrc.id = COALESCE((d.metadata->>'source_document_id')::BIGINT, 0)")
                            ->whereRaw("NULLIF(TRIM(COALESCE((dsrc.metadata->>'restaurant_table_id'), '')), '') IS NOT NULL");
                    });
            });
        }

        if ($documentKind) {
            $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $documentKind))));
            $normalizedKinds = array_values(array_filter(array_map(function ($kind) {
                return strtoupper(trim((string) $kind));
            }, $kinds)));

            if (count($normalizedKinds) > 0) {
                $query->where(function (Builder $nested) use ($normalizedKinds): void {
                    foreach ($normalizedKinds as $kind) {
                        if ($kind === 'CREDIT_NOTE' || $kind === 'DEBIT_NOTE') {
                            $nested->orWhereRaw('UPPER(d.document_kind) LIKE ?', [$kind . '%']);
                            continue;
                        }

                        $nested->orWhereRaw('UPPER(d.document_kind) = ?', [$kind]);
                    }
                });
            }
        }

        if ($documentKindId) {
            $ids = array_values(array_filter(array_map(function ($id) {
                $value = (int) trim((string) $id);
                return $value > 0 ? $value : null;
            }, explode(',', (string) $documentKindId))));

            if (count($ids) > 0) {
                $fallbackCodes = $this->loadDocumentKindCodesByIds($ids);

                $query->where(function (Builder $nested) use ($ids, $fallbackCodes): void {
                    $nested->whereIn('d.document_kind_id', $ids);

                    if (!empty($fallbackCodes)) {
                        $nested->orWhere(function (Builder $legacy) use ($fallbackCodes): void {
                            $legacy->whereNull('d.document_kind_id')
                                ->whereIn(DB::raw('UPPER(d.document_kind)'), $fallbackCodes);
                        });
                    }
                });
            }
        }

        if ($status) {
            $query->where('d.status', (string) $status);
        }

        if ($customerId > 0) {
            $query->where('d.customer_id', $customerId);
        } elseif ($customer !== '') {
            $like = strlen($customer) <= 3 ? $customer . '%' : '%' . $customer . '%';
            $query->where(function (Builder $nested) use ($like, $workshopVehicleSearchEnabled): void {
                $nested->where('c.legal_name', 'ilike', $like)
                    ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) ILIKE ?", [$like])
                    ->orWhere('c.doc_number', 'ilike', $like);

                if ($workshopVehicleSearchEnabled) {
                    $nested->orWhereExists(function (Builder $vehicleQuery) use ($like): void {
                        $vehicleQuery->select(DB::raw('1'))
                            ->from('sales.customer_vehicles as cv')
                            ->whereColumn('cv.customer_id', 'c.id')
                            ->whereColumn('cv.company_id', 'd.company_id')
                            ->where('cv.status', 1)
                            ->where(function (Builder $vehicleNested) use ($like): void {
                                $vehicleNested->where('cv.plate', 'ilike', $like)
                                    ->orWhere('cv.brand', 'ilike', $like)
                                    ->orWhere('cv.model', 'ilike', $like);
                            });
                    });
                }
            });
        }

        if ($workshopVehicleSearchEnabled && $vehicle !== '') {
            $vehicleLike = strlen($vehicle) <= 3 ? $vehicle . '%' : '%' . $vehicle . '%';
            $query->where(function (Builder $nested) use ($vehicleLike): void {
                $nested->whereRaw("COALESCE((d.metadata->>'vehicle_plate'), (d.metadata->>'vehiclePlateSnapshot'), '') ILIKE ?", [$vehicleLike]);
            });
        }

        if ($workshopVehicleSearchEnabled && $customerVehicleId !== null && $customerVehicleId !== '') {
            $query->whereRaw("COALESCE((d.metadata->>'customer_vehicle_id'), (d.metadata->>'customerVehicleId'), '0')::BIGINT = ?", [(int) $customerVehicleId]);
        }

        if ($issueDateFrom) {
            $query->where('d.issue_at', '>=', $issueDateFrom . self::DAY_START_SUFFIX);
        }

        if ($issueDateTo) {
            $query->where('d.issue_at', '<=', $issueDateTo . self::DAY_END_SUFFIX);
        }

        if ($series !== '') {
            $seriesLike = strlen($series) <= 3 ? $series . '%' : '%' . $series . '%';
            $query->where('d.series', 'ilike', $seriesLike);
        }

        if ($number !== '') {
            if (ctype_digit($number)) {
                $query->where('d.number', (int) $number);
            } else {
                $query->whereRaw('CAST(d.number AS TEXT) ILIKE ?', ['%' . $number . '%']);
            }
        }

        if ($conversionState === 'PENDING') {
            $query
                ->whereIn('d.document_kind', ['QUOTATION', 'SALES_ORDER'])
                ->whereRaw("NOT EXISTS (
                    SELECT 1
                    FROM sales.commercial_documents d2
                    WHERE d2.company_id = d.company_id
                      AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                      AND d2.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                )")
                ->whereRaw("(
                    d.document_kind <> 'QUOTATION'
                    OR NOT EXISTS (
                        SELECT 1
                        FROM sales.commercial_documents d3
                        WHERE d3.company_id = d.company_id
                          AND d3.document_kind = 'SALES_ORDER'
                          AND d3.status NOT IN ('VOID', 'CANCELED')
                          AND COALESCE((d3.metadata->>'source_document_id')::BIGINT, 0) = d.id
                    )
                )");
        }

        if ($conversionState === 'CONVERTED') {
            $query
                ->whereIn('d.document_kind', ['QUOTATION', 'SALES_ORDER'])
                ->whereRaw("(
                    EXISTS (
                        SELECT 1
                        FROM sales.commercial_documents d2
                        WHERE d2.company_id = d.company_id
                          AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                          AND d2.status NOT IN ('VOID', 'CANCELED')
                          AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = d.id
                    )
                    OR (
                        d.document_kind = 'QUOTATION'
                        AND EXISTS (
                            SELECT 1
                            FROM sales.commercial_documents d3
                            WHERE d3.company_id = d.company_id
                              AND d3.document_kind = 'SALES_ORDER'
                              AND d3.status NOT IN ('VOID', 'CANCELED')
                              AND COALESCE((d3.metadata->>'source_document_id')::BIGINT, 0) = d.id
                        )
                    )
                )");
        }
    }

    private function loadDocumentKindCodesByIds(array $ids): array
    {
        if (!$this->tableExistsBySchemaAndName('sales', 'document_kinds')) {
            return [];
        }

        return DB::table('sales.document_kinds')
            ->whereIn('id', $ids)
            ->pluck('code')
            ->map(function ($code) {
                return strtoupper(trim((string) $code));
            })
            ->filter(function ($code) {
                return $code !== '';
            })
            ->values()
            ->all();
    }

    private function tableExistsBySchemaAndName(string $schema, string $table): bool
    {
        $cacheKey = strtolower(trim($schema)) . '.' . strtolower(trim($table));
        if (array_key_exists($cacheKey, $this->tableExistsCache)) {
            return $this->tableExistsCache[$cacheKey];
        }

        $present = (bool) Cache::remember(
            'sales_lookup:table_exists:' . $cacheKey,
            self::TABLE_EXISTS_CACHE_TTL_SECONDS,
            function () use ($schema, $table): bool {
                $row = DB::selectOne(
                    'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
                    [$schema, $table]
                );

                return isset($row->present) && (bool) $row->present;
            }
        );

        $this->tableExistsCache[$cacheKey] = $present;

        return $present;
    }

    private function tableColumnsByQualifiedTable(string $qualifiedTable): array
    {
        $cacheKey = strtolower(trim($qualifiedTable));
        if (array_key_exists($cacheKey, $this->tableColumnsCache)) {
            return $this->tableColumnsCache[$cacheKey];
        }

        [$schema, $table] = $this->splitQualifiedTable($qualifiedTable);

        $columns = collect(DB::select(
            'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [$schema, $table]
        ))->map(function ($row) {
            return (string) $row->column_name;
        })->all();

        $this->tableColumnsCache[$cacheKey] = $columns;

        return $columns;
    }

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') === false) {
            return ['public', $qualifiedTable];
        }

        [$schema, $table] = explode('.', $qualifiedTable, 2);

        return [$schema, $table];
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    public function updateCompanySettings(int $companyId, array $values): void
    {
        DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->update($values);
    }
}
