<?php

namespace App\Services\Sales;

use App\Application\DTOs\AppConfig\CompanyFeatureToggleDTO;
use App\Application\DTOs\AppConfig\CompanyProfileDTO;
use App\Application\DTOs\AppConfig\CompanySettingsDTO;
use App\Application\DTOs\Inventory\InventorySettingsDTO;
use App\Application\DTOs\Inventory\InventoryStockLevelDTO;
use App\Application\DTOs\Sales\PosStationConflictDTO;
use App\Application\DTOs\Sales\SalesCustomerIdentityDTO;
use App\Application\DTOs\Sales\TaxBridgeDebugDocumentDTO;
use App\Infrastructure\Repositories\Sales\SalesLookupRepository;
use Illuminate\Support\Collection;

class SalesLookupService
{
    private static bool $documentKindsTableEnsured = false;
    private static bool $customersPhoneColumnEnsured = false;
    private static bool $companyRoleProfilesTableEnsured = false;

    public function __construct(private SalesLookupRepository $repository)
    {
    }

    public function branchExists(int $companyId, int $branchId): bool
    {
        return $this->repository->branchExists($companyId, $branchId);
    }

    public function activeWarehouseExistsInBranchScope(int $companyId, int $warehouseId, int $branchId): bool
    {
        return $this->repository->activeWarehouseExistsInBranchScope($companyId, $warehouseId, $branchId);
    }

    public function resolveDefaultWarehouseIdByBranchScope(int $companyId, int $branchId): ?int
    {
        return $this->repository->resolveDefaultWarehouseIdByBranchScope($companyId, $branchId);
    }

    public function listActiveCurrencies(): Collection
    {
        return $this->repository->listActiveCurrencies();
    }

    public function listActivePaymentTypes(): Collection
    {
        return $this->repository->listActivePaymentTypes();
    }
    
    public function listPaymentMethodsForMasterData(): Collection
    {
        return $this->repository->listPaymentMethodsForMasterData();
    }
    
    public function createPaymentMethod(array $payload): int
    {
        return $this->repository->createPaymentMethod($payload);
    }
    
    public function paymentMethodExists(int $id): bool
    {
        return $this->repository->paymentMethodExists($id);
    }
    
    public function updatePaymentMethod(int $id, array $updates): void
    {
        $this->repository->updatePaymentMethod($id, $updates);
    }

    public function findCompanyById(int $companyId, array $columns): ?CompanyProfileDTO
    {
        return $this->repository->findCompanyById($companyId, $columns);
    }

    public function findLatestCompanySettings(
        int $companyId,
        array $columns,
        bool $preferRowsWithLogo,
        bool $orderByUpdatedAt,
        bool $orderByCreatedAt
    ): ?CompanySettingsDTO {
        return $this->repository->findLatestCompanySettings(
            $companyId,
            $columns,
            $preferRowsWithLogo,
            $orderByUpdatedAt,
            $orderByCreatedAt
        );
    }

    public function listSeriesNumbers(
        int $companyId,
        ?int $branchId,
        ?int $warehouseId,
        bool $enabledOnly,
        ?int $documentKindId,
        ?string $documentKindCode
    ): Collection {
        return $this->repository->listSeriesNumbers(
            $companyId,
            $branchId,
            $warehouseId,
            $enabledOnly,
            $documentKindId,
            $documentKindCode
        );
    }

    public function createSeries(int $companyId, int $authUserId, array $payload): int
    {
        return $this->repository->createSeries($companyId, $authUserId, $payload);
    }

    public function updateSeries(int $companyId, int $authUserId, int $id, array $payload): void
    {
        $this->repository->updateSeries($companyId, $authUserId, $id, $payload);
    }
    
    public function listPriceTiersForCompany(int $companyId): Collection
    {
        return $this->repository->listPriceTiersForCompany($companyId);
    }

    public function resolveDocumentKindIdByCode(string $code): ?int
    {
        return $this->repository->resolveDocumentKindIdByCode($code);
    }

    public function resolveDocumentKindIdsByCodes(array $codes): array
    {
        return $this->repository->resolveDocumentKindIdsByCodes($codes);
    }

    public function resolveDocumentKindAliasesByCodes(array $codes): array
    {
        return $this->repository->resolveDocumentKindAliasesByCodes($codes);
    }

    public function resolveDocumentKindIdMapByCodes(array $codes): array
    {
        return $this->repository->resolveDocumentKindIdMapByCodes($codes);
    }

    public function resolveCanonicalDocumentKindCode(string $documentKindValue, ?int $documentKindId = null): ?string
    {
        return $this->repository->resolveCanonicalDocumentKindCode($documentKindValue, $documentKindId);
    }

    public function ensureDocumentKindsTable(): void
    {
        if (self::$documentKindsTableEnsured) {
            return;
        }

        $this->repository->ensureDocumentKindsTable();
        self::$documentKindsTableEnsured = true;
    }

    public function listDocumentKindsCatalog(): array
    {
        return $this->repository->listDocumentKindsCatalog()->all();
    }

    public function createDocumentKind(int $companyId, int $authUserId, array $payload): void
    {
        $this->repository->createDocumentKind($companyId, $authUserId, $payload);
    }

    public function updateDocumentKind(int $companyId, int $authUserId, int $id, array $payload): void
    {
        $this->repository->updateDocumentKind($companyId, $authUserId, $id, $payload);
    }

    public function findCommercialDocumentBranchId(int $companyId, int $documentId): ?int
    {
        return $this->repository->findCommercialDocumentBranchId($companyId, $documentId);
    }

    public function findUserPasswordHashById(int $userId): ?string
    {
        return $this->repository->findUserPasswordHashById($userId);
    }

    public function paginateCommercialDocuments(int $companyId, array $filters, int $page, int $limit): array
    {
        return $this->repository->paginateCommercialDocuments($companyId, $filters, $page, $limit);
    }

    public function listCommercialDocumentsForExport(int $companyId, array $filters, int $max): Collection
    {
        return $this->repository->listCommercialDocumentsForExport($companyId, $filters, $max);
    }

    public function listCommercialDocumentProductsForExport(int $companyId, array $filters, int $max): Collection
    {
        return $this->repository->listCommercialDocumentProductsForExport($companyId, $filters, $max);
    }

    public function listTopProducts(int $companyId, int $limit, int $days): array
    {
        return $this->repository->listTopProducts($companyId, $limit, $days);
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
        $this->repository->registerCashIncomeFromDocument(
            $companyId,
            $branchId,
            $cashRegisterId,
            $documentId,
            $documentKind,
            $series,
            $number,
            $paidTotal,
            $userId,
            $paymentMethodId
        );
    }

    public function resolveTaxCategoriesRows(int $companyId): array
    {
        return $this->repository->resolveTaxCategoriesRows($companyId);
    }

    public function resolveDocumentNoteReasonsRows(string $normalizedKind): array
    {
        return $this->repository->resolveDocumentNoteReasonsRows($normalizedKind);
    }

    public function resolveCompanyBankAccountsRaw(int $companyId): mixed
    {
        return $this->repository->resolveCompanyBankAccountsRaw($companyId);
    }

    public function findVerticalFeatureOverride(int $companyId, int $verticalId, string $featureCode): ?CompanyFeatureToggleDTO
    {
        return $this->repository->findVerticalFeatureOverride($companyId, $verticalId, $featureCode);
    }

    public function findVerticalFeatureTemplate(int $verticalId, string $featureCode): ?CompanyFeatureToggleDTO
    {
        return $this->repository->findVerticalFeatureTemplate($verticalId, $featureCode);
    }

    public function loadVerticalFeatureOverrides(int $companyId, int $verticalId): Collection
    {
        return $this->repository->loadVerticalFeatureOverrides($companyId, $verticalId);
    }

    public function loadVerticalFeatureTemplates(int $verticalId): Collection
    {
        return $this->repository->loadVerticalFeatureTemplates($verticalId);
    }

    public function resolveActiveCompanyVertical(int $companyId): ?array
    {
        return $this->repository->resolveActiveCompanyVertical($companyId);
    }

    public function resolveDetractionServiceCodes(): array
    {
        return $this->repository->resolveDetractionServiceCodes();
    }

    public function tableExists(string $qualifiedTable): bool
    {
        return $this->repository->tableExists($qualifiedTable);
    }

    public function tableColumns(string $qualifiedTable): array
    {
        return $this->repository->tableColumns($qualifiedTable);
    }

    public function loadCompanyFeatureToggles(int $companyId): Collection
    {
        return $this->repository->loadCompanyFeatureToggles($companyId);
    }
    
    public function listDocumentKindsForCompany(int $companyId): Collection
    {
        $toggles = $this->loadCompanyFeatureToggles($companyId)
            ->pluck('is_enabled', 'feature_code');

        return collect($this->listDocumentKindsCatalog())->map(function (array $row) use ($toggles) {
            $featureCode = 'DOC_KIND_' . (string) $row['code'];

            return [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) $row['code'],
                'label' => (string) $row['label'],
                'feature_code' => $featureCode,
                'is_enabled' => ((bool) ($row['is_enabled'] ?? true))
                    && ($toggles->has($featureCode) ? (bool) $toggles->get($featureCode) : true),
            ];
        })->values();
    }

    public function loadBranchFeatureToggles(int $companyId, int $branchId): Collection
    {
        return $this->repository->loadBranchFeatureToggles($companyId, $branchId);
    }

    public function enabledUnits(int $companyId): Collection
    {
        return $this->repository->enabledUnits($companyId);
    }

    public function ensureCustomersPhoneColumn(): void
    {
        if (self::$customersPhoneColumnEnsured) {
            return;
        }

        $this->repository->ensureCustomersPhoneColumn();
        self::$customersPhoneColumnEnsured = true;
    }

    public function fetchCustomerIdentityForSalesValidation(int $companyId, int $customerId): ?SalesCustomerIdentityDTO
    {
        return $this->repository->fetchCustomerIdentityForSalesValidation($companyId, $customerId);
    }

    public function resolveFallbackPaymentMethodId(int $companyId): ?int
    {
        return $this->repository->resolveFallbackPaymentMethodId($companyId);
    }

    public function inventorySettingsForCompany(int $companyId): ?InventorySettingsDTO
    {
        return $this->repository->inventorySettingsForCompany($companyId);
    }
    
    public function listLotsForCompany(int $companyId, ?int $productId = null, ?int $warehouseId = null, int $limit = 300): Collection
    {
        return $this->repository->listLotsForCompany($companyId, $productId, $warehouseId, $limit);
    }
    
    public function companyProductExists(int $companyId, int $productId): bool
    {
        return $this->repository->companyProductExists($companyId, $productId);
    }
    
    public function companyWarehouseExists(int $companyId, int $warehouseId): bool
    {
        return $this->repository->companyWarehouseExists($companyId, $warehouseId);
    }
    
    public function createLot(int $companyId, int $userId, array $payload): int
    {
        return $this->repository->createLot($companyId, $userId, $payload);
    }

    public function buildDashboardData(int $companyId, bool $includePosStations): array
    {
        return $this->repository->buildDashboardData($companyId, $includePosStations);
    }

    public function updateDocumentKindsBulk(int $companyId, int $authUserId, array $items): void
    {
        $this->repository->updateDocumentKindsBulk($companyId, $authUserId, $items);
    }

    public function companyUserExists(int $companyId, int $userId): bool
    {
        return $this->repository->companyUserExists($companyId, $userId);
    }

    public function warehouseExists(int $companyId, int $warehouseId): bool
    {
        return $this->repository->warehouseExists($companyId, $warehouseId);
    }

    public function countEnabledWarehouses(int $companyId): int
    {
        return $this->repository->countEnabledWarehouses($companyId);
    }

    public function createWarehouse(int $companyId, array $payload): int
    {
        return $this->repository->createWarehouse($companyId, $payload);
    }

    public function updateWarehouse(int $companyId, int $warehouseId, array $updates): void
    {
        $this->repository->updateWarehouse($companyId, $warehouseId, $updates);
    }

    public function listCashRegistersForCompany(int $companyId): Collection
    {
        return $this->repository->listCashRegistersForCompany($companyId);
    }

    public function posStationsTableExists(): bool
    {
        return $this->repository->posStationsTableExists();
    }

    public function listPosStationsForCompany(int $companyId): Collection
    {
        return $this->repository->listPosStationsForCompany($companyId);
    }

    public function activeWarehouseExists(int $companyId, int $warehouseId): bool
    {
        return $this->repository->activeWarehouseExists($companyId, $warehouseId);
    }

    public function cashRegisterExists(int $companyId, int $cashRegisterId): bool
    {
        return $this->repository->cashRegisterExists($companyId, $cashRegisterId);
    }

    public function activeCashRegisterExists(int $companyId, int $cashRegisterId): bool
    {
        return $this->repository->activeCashRegisterExists($companyId, $cashRegisterId);
    }

    public function countEnabledCashRegisters(int $companyId): int
    {
        return $this->repository->countEnabledCashRegisters($companyId);
    }

    public function countEnabledCashRegistersForWarehouse(int $companyId, int $warehouseId): int
    {
        return $this->repository->countEnabledCashRegistersForWarehouse($companyId, $warehouseId);
    }

    public function createCashRegister(int $companyId, array $payload, int $warehouseId): int
    {
        return $this->repository->createCashRegister($companyId, $payload, $warehouseId);
    }

    public function updateCashRegister(int $companyId, int $cashRegisterId, array $updates): void
    {
        $this->repository->updateCashRegister($companyId, $cashRegisterId, $updates);
    }

    public function posStationExists(int $companyId, int $stationId): bool
    {
        return $this->repository->posStationExists($companyId, $stationId);
    }

    public function posStationCodeExists(int $companyId, string $normalizedCode, ?int $excludeId = null): bool
    {
        return $this->repository->posStationCodeExists($companyId, $normalizedCode, $excludeId);
    }

    public function findPosStationDeviceConflict(int $companyId, string $normalizedDeviceId, ?int $excludeId = null): ?PosStationConflictDTO
    {
        return $this->repository->findPosStationDeviceConflict($companyId, $normalizedDeviceId, $excludeId);
    }

    public function createPosStation(int $companyId, int $cashRegisterId, array $payload, string $normalizedCode, string $normalizedDeviceId): int
    {
        return $this->repository->createPosStation($companyId, $cashRegisterId, $payload, $normalizedCode, $normalizedDeviceId);
    }

    public function updatePosStation(int $companyId, int $stationId, array $updates): void
    {
        $this->repository->updatePosStation($companyId, $stationId, $updates);
    }

    public function ensureCompanyRoleProfilesTable(): void
    {
        if (self::$companyRoleProfilesTableEnsured) {
            return;
        }

        $this->repository->ensureCompanyRoleProfilesTable();
        self::$companyRoleProfilesTableEnsured = true;
    }

    public function buildAccessControlData(int $companyId): array
    {
        return $this->repository->buildAccessControlData($companyId);
    }

    public function listCompanyFunctionalProfiles(int $companyId): Collection
    {
        return $this->repository->listCompanyFunctionalProfiles($companyId);
    }

    public function createCompanyFunctionalProfile(int $companyId, int $updatedBy, array $payload): void
    {
        $this->repository->insertCompanyFunctionalProfile($companyId, $updatedBy, $payload);
    }

    public function updateCompanyFunctionalProfile(int $companyId, string $code, array $updates, ?int $updatedBy): void
    {
        $this->repository->updateCompanyFunctionalProfile($companyId, $code, $updates, $updatedBy);
    }

    public function functionalProfileCodes(int $companyId): array
    {
        return $this->listCompanyFunctionalProfiles($companyId)
            ->where('status', 1)
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->values()
            ->all();
    }

    public function normalizeFunctionalProfile($value, ?array $allowedCodes = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if ($allowedCodes !== null && !in_array($normalized, $allowedCodes, true)) {
            return null;
        }

        return $normalized;
    }

    public function syncRoleFunctionalProfile(int $companyId, int $roleId, ?string $functionalProfile, ?int $updatedBy): void
    {
        $this->repository->syncCompanyRoleFunctionalProfile($companyId, $roleId, $functionalProfile, $updatedBy);
    }

    public function createRole(int $companyId, string $code, string $name, int $status): int
    {
        return $this->repository->createRole($companyId, $code, $name, $status);
    }

    public function updateRole(int $companyId, int $roleId, array $updates): void
    {
        $this->repository->updateRole($companyId, $roleId, $updates);
    }

    public function roleExists(int $companyId, int $roleId): bool
    {
        return $this->repository->roleExists($companyId, $roleId);
    }

    public function createCompanyUser(int $companyId, array $payload): int
    {
        return $this->repository->createCompanyUser($companyId, $payload);
    }

    public function updateCompanyUser(int $companyId, int $id, array $payload): void
    {
        $this->repository->updateCompanyUser($companyId, $id, $payload);
    }

    public function syncRolePermissions(int $roleId, array $permissions): void
    {
        $this->repository->syncRolePermissions($roleId, $permissions);
    }

    public function resolveAuthRoleContext(int $userId, int $companyId): array
    {
        $row = $this->repository->resolveAuthRoleContext($userId, $companyId);

        return [
            'role_code' => $row && $row->role_code !== null ? (string) $row->role_code : null,
            'role_profile' => $row && $row->role_profile !== null ? (string) $row->role_profile : null,
        ];
    }

    public function findProductUomConversionFactor(int $companyId, int $productId, int $fromUnitId, int $toUnitId): ?float
    {
        return $this->repository->findProductUomConversionFactor($companyId, $productId, $fromUnitId, $toUnitId);
    }

    public function findCurrentStockRow(int $companyId, int $warehouseId, int $productId): ?InventoryStockLevelDTO
    {
        return $this->repository->findCurrentStockRow($companyId, $warehouseId, $productId);
    }

    public function listCandidateOutboundLots(int $companyId, int $warehouseId, int $productId, string $strategy): Collection
    {
        return $this->repository->listCandidateOutboundLots($companyId, $warehouseId, $productId, $strategy);
    }

    public function findCurrentStockByLotRow(int $companyId, int $warehouseId, int $productId, int $lotId): ?InventoryStockLevelDTO
    {
        return $this->repository->findCurrentStockByLotRow($companyId, $warehouseId, $productId, $lotId);
    }

    public function findTaxBridgeDocumentForDebug(int $companyId, int $documentId): ?TaxBridgeDebugDocumentDTO
    {
        return $this->repository->findTaxBridgeDocumentForDebug($companyId, $documentId);
    }

    public function updateCompanySettings(int $companyId, array $values): void
    {
        $this->repository->updateCompanySettings($companyId, $values);
    }
}
