# Architecture Layer Sweep - 2026-05-28

## Scope
General sweep inventory to migrate legacy code toward:
- Controller -> Service -> Repository
- External integrations via Gateway

## Hotspots (DB direct usage count by file)

### Controllers
- 181: app/Http/Controllers/Api/AppConfigController.php
- 163: app/Http/Controllers/Api/SalesController.php
- 161: app/Http/Controllers/Api/MasterDataController.php
- 91: app/Http/Controllers/Api/CashController.php
- 40: app/Http/Controllers/Api/InventoryController.php
- 38: app/Http/Controllers/Api/PurchasesController.php

### Services
- 57: app/Services/Sales/TaxBridge/GreGuideService.php
- 48: app/Services/Inventory/InventoryProductService.php
- 44: app/Services/Sales/TaxBridge/DailySummaryService.php
- 39: app/Services/Sales/TaxBridge/TaxBridgeService.php
- 30: app/Services/Restaurant/RestaurantOrderService.php
- 26: app/Services/Restaurant/RestaurantRecipeService.php

## Cleanup Completed in this sweep
- Customer management moved to repository + gateway pattern.
- Supplier management moved to repository + gateway pattern.
- SalesDocumentNoteValidationService now uses CommercialDocumentRepository (no direct DB).
- SalesDocumentLinePersistenceService now uses repository for inventory ledger inserts (no direct DB).
- SalesController convertCommercialDocument now delegates conversion DB lookups to SalesDocumentConversionService + repositories.
- SalesController showCommercialDocument now delegates header/items fallback/lots DB reads to SalesDocumentReadService + repositories.
- AppConfigController operationalContext flow now delegates company/branches/warehouses/cash-register reads to OperationalContextService + OperationalContextRepository.
- AppConfigController commerceSettings/updateCommerceSettings branch-scope validation now delegates to OperationalContextService (no direct branch-exists DB query in controller).
- AppConfigController homeMetricsSummary now delegates sales/purchases aggregate reads and cache orchestration to HomeMetricsSummaryService + HomeMetricsSummaryRepository.
- AppConfigController operationalLimits/updateOperationalLimits and active vertical resolution now delegate to OperationalLimitsService + OperationalLimitsRepository.
- AppConfigController vertical feature preference resolution now delegates override/template reads to VerticalFeaturePreferenceService + VerticalFeaturePreferenceRepository.
- AppConfigController feature label reads/persistence (allowed codes, labels, categories) now delegate to FeatureLabelService + FeatureLabelRepository.
- AppConfigController station context resolution (refresh token device + POS station join) now delegates to StationContextService + StationContextRepository.
- AppConfigController company operational limit matrix endpoints now delegate to OperationalLimitsService + OperationalLimitsRepository.
- AppConfigController company access link persistence/lookup now delegates to CompanyAccessLinkService + CompanyAccessLinkRepository.
- AppConfigController company rate-limit audit insert now delegates to OperationalLimitsService + OperationalLimitsRepository.
- AppConfigController companyVerticalAdminMatrix read flow now delegates to VerticalAdminMatrixService + VerticalAdminMatrixRepository.
- AppConfigController companyRateLimitMatrix/update single/update bulk now delegate to CompanyRateLimitService + CompanyRateLimitRepository.
- AppConfigController updateCompanyVerticalAdminMatrix and updateCompanyVerticalAdminMatrixBulk now delegate write orchestration to VerticalAdminMatrixService + VerticalAdminMatrixRepository.
- AppConfigController companyVerticalSettings and updateCompanyVerticalSettings now delegate to VerticalAdminMatrixService + VerticalAdminMatrixRepository.
- AppConfigController modules and featureToggles DB reads now delegate to ModuleToggleService + ModuleToggleRepository.
- AppConfigController createAdminCompany full provisioning transaction now delegates to AdminCompanyProvisioningService + AdminCompanyProvisioningRepository.
- AppConfigController reset/reveal admin password lookup/update now delegates to AdminCompanyProvisioningService + AdminCompanyProvisioningRepository.
- AppConfigController repairCompanyAdminRoleAfterRestore now delegates to AdminCompanyProvisioningService + AdminCompanyProvisioningRepository.
- AppConfigController backup/restore DB workflow and FK/table metadata reads now delegate to BackupMaintenanceService + BackupMaintenanceRepository.
- AppConfigController company profile, profile update, logo update and certificate bridge payload reads/writes now delegate to CompanyProfileService + CompanyProfileRepository.
- AppConfigController admin commerce matrix, SUNAT reconcile matrix and inventory settings matrix now delegate to AdminSettingsMatrixService + AdminSettingsMatrixRepository.
- SalesController lookups and company print profile reads now delegate to SalesLookupService + SalesLookupRepository.
- SalesController customer vehicles and customer types workflows now delegate to CustomerVehicleService + CustomerVehicleRepository.
- SalesController referenceDocuments and priceTiers reads now delegate to ReferenceDocumentService + ReferenceDocumentRepository.
- SalesController createCommercialDocument scope/vehicle/source-document validations now delegate to SalesDocumentValidationService + SalesDocumentValidationRepository.
- SalesController note/RUC/source-note business rules now delegate to SalesBusinessRuleService.
- SalesController seriesNumbers DB query now delegates to SalesLookupService + SalesLookupRepository.
- SalesController voidCommercialDocument context/password DB reads now delegate to SalesLookupService + SalesLookupRepository.
- InventoryController schema bootstrap, company-units bootstrap, profile-feature checks, unit/warehouse resolution, stock projection reads and duplicate product lookups now delegate to InventoryControllerSupportService + InventoryControllerSupportRepository.
- SalesController commercialDocuments and exportCommercialDocuments query/filter execution now delegate to SalesLookupService + SalesLookupRepository.
- SalesController vehicle snapshot fallback read now delegates to CustomerVehicleService + CustomerVehicleRepository.
- SalesController document-kind catalog, customer identity validation lookup, fallback payment-method resolution, role-context resolution, UOM conversion lookups, stock projection reads, lot-candidate reads, and tax-bridge debug document lookup now delegate to SalesLookupService + SalesLookupRepository.
- AuthController login/refresh/logout/session scope validation, role-context resolution, permissions matrix resolution, and admin-portal feature gates now delegate to AuthSessionService + AuthSessionRepository.
- GreGuideController branch scope validation, guide scope lookup, and traceability feature gate checks now delegate to GreGuideService.
- TaxBridgeAuditController document/log scope lookups and traceability feature gate checks now delegate to TaxBridgeAuditService.
- OpsLatencyController summary SQL aggregation now delegates to OpsLatencyService + OpsLatencyRepository.

## Current Controller Lint Delta (AppConfigController)
- strict violations: 160 -> 153 -> 151 -> 143 -> 137 -> 132 -> 126 -> 94 -> 87 -> 81 -> 58 -> 53 -> 42 -> 30 -> 14 -> 0

## Current Controller Lint Delta (SalesController)
- strict violations: 151 -> 144 -> 128 -> 121 -> 114 -> 112 -> 110 -> 70 -> 35 -> 34 -> 16 -> 9 -> 0

## Current Controller Lint Delta (InventoryController)
- strict violations: 40 -> 11 -> 0

## Current Controller Lint Delta (AuthController)
- strict violations: 20 -> 0

## Current Controller Lint Delta (GreGuideController)
- strict violations: 4 -> 0

## Current Controller Lint Delta (TaxBridgeAuditController)
- strict violations: 4 -> 0

## Current Controller Lint Delta (OpsLatencyController)
- strict violations: 1 -> 0

## Global Strict Delta (Api Controllers)
- strict violations: 372 -> 348 -> 343

## Next Mandatory Refactor Batches
1. SalesController feature/config and document query helpers -> dedicated services/repositories.
2. AppConfigController -> AppConfig query services + repositories.
3. CashController -> Cash service + repositories.
4. InventoryProductService split: orchestration vs repository persistence blocks.

## New Development Rule
For every new endpoint/feature:
- No DB in controllers.
- No DB/Http in services.
- Use repositories for persistence and gateways for external HTTP.
