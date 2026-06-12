<?php

namespace App\Http\Controllers\Api;

use App\Contracts\TaxBridgeGateway;
use App\Application\Factories\Sales\CreateCommercialDocumentCommandFactory;
use App\Application\UseCases\Sales\CreateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\PrepareCreateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\PrepareConvertCommercialDocumentUseCase;
use App\Application\UseCases\Sales\PrepareUpdateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\PrepareVoidCommercialDocumentUseCase;
use App\Application\UseCases\Sales\ResolveCompanyPrintProfileUseCase;
use App\Application\UseCases\Sales\UpdateCommercialDocumentDraftUseCase;
use App\Application\UseCases\Sales\VoidCommercialDocumentUseCase;
use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ConvertCommercialDocumentRequest;
use App\Http\Requests\Sales\CreateCommercialDocumentRequest;
use App\Http\Requests\Sales\CreateCustomerVehicleRequest;
use App\Http\Requests\Sales\SunatVoidCommunicationRequest;
use App\Http\Requests\Sales\UpdateCommercialDocumentRequest;
use App\Http\Requests\Sales\UpdateCustomerVehicleRequest;
use App\Http\Requests\Sales\VoidCommercialDocumentRequest;
use App\Services\AppConfig\CompanyIgvRateService;
use App\Services\Sales\CustomerQueryService;
use App\Services\Sales\CustomerVehicleService;
use App\Services\Sales\SalesBusinessRuleService;
use App\Services\Sales\SalesDocumentValidationService;
use App\Services\Sales\SalesLookupService;
use App\Services\Sales\Documents\SalesDocumentConversionService;
use App\Services\Sales\Documents\SalesDocumentReadService;
use App\Services\Sales\Documents\SalesDocumentException;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

class SalesController extends Controller
{    
    private const SELLER_ROLE_MARKERS = ['VENDED', 'SELLER', 'VENTA'];
    private const CASHIER_ROLE_MARKERS = ['CAJA', 'CAJER', 'CASHIER'];

    private $stockProjection = [];
    private $lotStockProjection = [];
    private array $activeVerticalCache = [];
    private array $verticalFeaturePreferenceCache = [];
    private array $verticalOverrideMap = [];
    private array $verticalTemplateMap = [];
    private array $verticalFeatureMapPrewarmed = [];
    private array $featureContextResolutionCache = [];
    private array $tableExistsCache = [];
    private array $companyFeatureToggleMap = [];  // companyId:FEATURE_CODE => stdClass|null
    private array $branchFeatureToggleMap = [];   // companyId:branchId:FEATURE_CODE => stdClass|null
    private array $featureTogglePrewarmed = [];    // companyId => true, companyId:branchId => true

    public function __construct(
        private CompanyIgvRateService $companyIgvRateService,
        private TaxBridgeGateway $taxBridgeService,
        private CreateCommercialDocumentCommandFactory $createCommercialDocumentCommandFactory,
        private PrepareCreateCommercialDocumentUseCase $prepareCreateCommercialDocumentUseCase,
        private PrepareConvertCommercialDocumentUseCase $prepareConvertCommercialDocumentUseCase,
        private PrepareUpdateCommercialDocumentUseCase $prepareUpdateCommercialDocumentUseCase,
        private PrepareVoidCommercialDocumentUseCase $prepareVoidCommercialDocumentUseCase,
        private ResolveCompanyPrintProfileUseCase $resolveCompanyPrintProfileUseCase,
        private CreateCommercialDocumentUseCase $createCommercialDocumentUseCase,
        private UpdateCommercialDocumentDraftUseCase $updateCommercialDocumentDraftUseCase,
        private VoidCommercialDocumentUseCase $voidCommercialDocumentUseCase,
        private CustomerQueryService $customerQueryService,
        private CustomerVehicleService $customerVehicleService,
        private SalesBusinessRuleService $salesBusinessRuleService,
        private SalesDocumentValidationService $salesDocumentValidationService,
        private SalesLookupService $salesLookupService,
        private SalesDocumentConversionService $salesDocumentConversionService,
        private SalesDocumentReadService $salesDocumentReadService
    )
    {
    }

    public function bootstrap(Request $request)
    {
        $includeDocuments = filter_var($request->query('include_documents', false), FILTER_VALIDATE_BOOLEAN);

        $lookupsResponse = $this->lookups($request);
        if ($lookupsResponse->getStatusCode() >= 400) {
            return $lookupsResponse;
        }

        $lookupsPayload = $lookupsResponse->getData(true);
        $documentsPayload = null;

        if ($includeDocuments) {
            $documentsResponse = $this->commercialDocuments($request);
            if ($documentsResponse->getStatusCode() >= 400) {
                return $documentsResponse;
            }

            $documentsPayload = $documentsResponse->getData(true);
        }

        return response()->json([
            'lookups' => $lookupsPayload,
            'documents' => $documentsPayload,
        ]);
    }

    public function lookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        if ($branchId !== null) {
            $branchExists = $this->salesLookupService->branchExists($companyId, $branchId);

            if (!$branchExists) {
                return response()->json([
                    'message' => 'Invalid branch scope',
                ], 422);
            }
        }

        // Pre-warm toggle maps once - all subsequent feature lookups use the in-memory cache (2 DB queries total)
        $this->prewarmFeatureToggles($companyId, $branchId);

        $currencies = $this->salesLookupService->listActiveCurrencies();

        $paymentMethods = $this->salesLookupService->listActivePaymentTypes();

        $catalog = $this->documentKindCatalog();

        // Use pre-warmed company toggle map instead of a separate query
        $ck = (string) $companyId;
        $enabledToggles = collect($this->companyFeatureToggleMap)
            ->filter(fn ($row, $key) => str_starts_with($key, $ck . ':DOC_KIND_'))
            ->mapWithKeys(fn ($row, $key) => [substr($key, strlen($ck) + 1) => $row->is_enabled]);

        $documentKinds = $catalog->filter(function ($row) use ($enabledToggles) {
            $featureCode = 'DOC_KIND_' . $row['code'];
            $tableEnabled = (bool) ($row['is_enabled'] ?? true);

            return $tableEnabled
                && (!$enabledToggles->has($featureCode) || (bool) $enabledToggles->get($featureCode));
        })->values();

        if ($documentKinds->isEmpty()) {
            $documentKinds = $catalog->values();
        }

        $commerceFeatureDefaults = [
            'SALES_SELLER_TO_CASHIER' => false,
            'SALES_CUSTOMER_PRICE_PROFILE' => false,
            'SALES_WORKSHOP_MULTI_VEHICLE' => false,
            'SALES_ORDER_MULTI_PAYMENT_ENABLED' => false,
            'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' => true,
            'SALES_ANTICIPO_ENABLED' => false,
            'SALES_TAX_BRIDGE' => false,
            'SALES_TAX_BRIDGE_DEBUG_VIEW' => false,
            'SALES_GLOBAL_DISCOUNT_ENABLED' => false,
            'SALES_ITEM_DISCOUNT_ENABLED' => false,
            'SALES_FREE_ITEMS_ENABLED' => false,
            'SALES_ALLOW_DRAFT_EDIT' => true,
            'SALES_ALLOW_DOCUMENT_VOID' => true,
            'SALES_ALLOW_VOID_FOR_SELLER' => true,
            'SALES_ALLOW_VOID_FOR_CASHIER' => true,
            'SALES_ALLOW_VOID_FOR_ADMIN' => true,
            'SALES_VOID_REQUIRE_PASSWORD' => false,
            'SALES_VOID_REVERSE_STOCK' => true,
        ];
        $commerceFeatureCodes = array_keys($commerceFeatureDefaults);

        $commerceFeatures = collect($commerceFeatureCodes)->map(function ($featureCode) use ($companyId, $branchId, $commerceFeatureDefaults) {
            $resolved = $this->resolveFeatureResolutionForContext(
                $companyId,
                $branchId,
                (string) $featureCode,
                (bool) ($commerceFeatureDefaults[$featureCode] ?? false)
            );

            return [
                'feature_code' => $featureCode,
                'is_enabled' => $resolved['is_enabled'],
                'company_enabled' => $resolved['company_enabled'],
                'branch_enabled' => $resolved['branch_enabled'],
                'config' => $resolved['config'],
                'vertical_source' => $resolved['vertical_source'],
            ];
        })->values();

        $salesDetraccionEnabled = $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_DETRACCION_ENABLED', false);
        $salesRetencionEnabled = $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_RETENCION_ENABLED', false);
        $salesPercepcionEnabled = $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_PERCEPCION_ENABLED', false);

        $taxCategories = $this->companyIgvRateService->applyActiveRateToTaxCategories(
            $companyId,
            $this->resolveTaxCategories($companyId)->all()
        );

        return response()->json([
            'document_kinds' => $documentKinds,
            'currencies' => $currencies,
            'payment_methods' => $paymentMethods,
            'tax_categories' => $taxCategories,
            'active_igv_rate_percent' => $this->companyIgvRateService->resolveActiveRatePercent($companyId),
            'units' => $this->enabledUnits($companyId),
            'inventory_settings' => $this->inventorySettingsForCompany($companyId),
            'credit_note_reasons' => $this->resolveDocumentNoteReasons('CREDIT_NOTE'),
            'debit_note_reasons' => $this->resolveDocumentNoteReasons('DEBIT_NOTE'),
            'detraccion_service_codes' => $salesDetraccionEnabled ? $this->resolveDetractionServiceCodes() : [],
            'detraccion_min_amount' => $salesDetraccionEnabled ? $this->getDetractionMinAmount($companyId, $branchId) : null,
            'detraccion_account' => $salesDetraccionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_DETRACCION_ENABLED', 'DETRACCION') : null,
            'retencion_types' => $salesRetencionEnabled ? $this->resolveRetencionTypes($companyId, $branchId) : [],
            'retencion_account' => $salesRetencionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_RETENCION_ENABLED', 'RETENCION') : null,
            'retencion_percentage' => $salesRetencionEnabled ? 3.00 : null,
            'percepcion_types' => $salesPercepcionEnabled ? $this->resolvePercepcionTypes($companyId, $branchId) : [],
            'percepcion_account' => $salesPercepcionEnabled ? $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_PERCEPCION_ENABLED', 'PERCEPCION') : null,
            'sunat_operation_types' => ($salesDetraccionEnabled || $salesRetencionEnabled || $salesPercepcionEnabled) ? $this->resolveSunatOperationTypes($companyId, $branchId) : [],
            'commerce_features' => $commerceFeatures,
            'company_profile' => $this->resolveCompanyPrintProfile($companyId),
        ]);
    }

    private function resolveCompanyPrintProfile(int $companyId): array
    {
        return $this->resolveCompanyPrintProfileUseCase->execute($companyId);
    }

    private function isFeatureEnabled(int $companyId, $branchId, string $featureCode): bool
    {
        $resolvedBranchId = $branchId !== null ? (int) $branchId : null;
        $resolved = $this->resolveFeatureResolutionForContext($companyId, $resolvedBranchId, $featureCode, false);

        return (bool) $resolved['is_enabled'];
    }

    public function customerAutocomplete(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, null)
            && $this->tableExists('sales.customer_vehicles');
        $search = trim((string) $request->query('q', ''));
        $status = 1;
        $limit = (int) $request->query('limit', 12);

        $rows = $this->customerQueryService->listCustomers(
            $companyId,
            $search,
            $status,
            $limit,
            true,
            $workshopVehicleSearchEnabled
        );

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function customers(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, null)
            && $this->tableExists('sales.customer_vehicles');

        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $limit = (int) $request->query('limit', 10000);

        $rows = $this->customerQueryService->listCustomers(
            $companyId,
            $search,
            $status,
            $limit,
            false,
            $workshopVehicleSearchEnabled
        );

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function customerVehicles(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $customerExists = $this->customerVehicleService->customerExists($companyId, $id);

        if (!$customerExists) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $rows = $this->customerVehicleService->listCustomerVehicles($companyId, $id);

        return response()->json(['data' => $rows]);
    }

    public function createCustomerVehicle(CreateCustomerVehicleRequest $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $customerExists = $this->customerVehicleService->customerExists($companyId, $id);

        if (!$customerExists) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $payload = $request->validated();

        if (array_key_exists('doc_number', $payload)) {
            $normalizedDoc = trim((string) ($payload['doc_number'] ?? ''));
            $payload['doc_number'] = $normalizedDoc !== '' ? $normalizedDoc : null;
        }
        $plateNormalized = $this->normalizeVehiclePlate((string) ($payload['plate'] ?? ''));
        if ($plateNormalized === '') {
            return response()->json(['message' => 'La placa ingresada no es valida'], 422);
        }

        $duplicate = $this->customerVehicleService->activePlateExists($companyId, $plateNormalized);

        if ($duplicate) {
            return response()->json(['message' => 'La placa ya esta registrada para otro cliente'], 422);
        }

        $created = $this->customerVehicleService->createCustomerVehicle($companyId, $id, $payload, $plateNormalized);

        return response()->json([
            'message' => 'Vehicle created',
            'id' => (int) $created['id'],
            'data' => $created['data'],
        ], 201);
    }

    public function updateCustomerVehicle(UpdateCustomerVehicleRequest $request, int $id, int $vehicleId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $vehicle = $this->customerVehicleService->findVehicle($companyId, $id, $vehicleId);

        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        $changes = $request->validated();
        $update = [];

        if (array_key_exists('plate', $changes)) {
            $plateNormalized = $this->normalizeVehiclePlate((string) $changes['plate']);
            if ($plateNormalized === '') {
                return response()->json(['message' => 'La placa ingresada no es valida'], 422);
            }

            $duplicate = $this->customerVehicleService->activePlateExists($companyId, $plateNormalized, $vehicleId);

            if ($duplicate) {
                return response()->json(['message' => 'La placa ya esta registrada para otro cliente'], 422);
            }

            $update['plate'] = strtoupper(trim((string) $changes['plate']));
            $update['plate_normalized'] = $plateNormalized;
        }

        foreach (['brand', 'model', 'year', 'color', 'vin', 'status'] as $field) {
            if (array_key_exists($field, $changes)) {
                $update[$field] = $changes[$field];
            }
        }

        if (array_key_exists('is_default', $changes)) {
            $isDefault = (bool) $changes['is_default'];
            if ($isDefault) {
                $this->customerVehicleService->clearDefaultVehicles($companyId, $id);
            }
            $update['is_default'] = $isDefault;
        }

        if (empty($update)) {
            return response()->json(['message' => 'No changes provided'], 422);
        }

        $update['updated_at'] = now();

        $this->customerVehicleService->updateVehicle($companyId, $id, $vehicleId, $update);

        return response()->json(['message' => 'Vehicle updated']);
    }

    public function deleteCustomerVehicle(Request $request, int $id, int $vehicleId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        if (!$this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)) {
            return response()->json(['message' => 'Funcionalidad no habilitada para esta empresa'], 404);
        }

        if (!$this->tableExists('sales.customer_vehicles')) {
            return response()->json(['message' => 'La tabla de vehiculos aun no existe en esta instancia'], 503);
        }

        $vehicle = $this->customerVehicleService->findVehicle($companyId, $id, $vehicleId, true);

        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        $this->customerVehicleService->handleDeleteVehicleAndDefaultFallback(
            $companyId,
            $id,
            $vehicleId,
            (bool) ($vehicle->is_default ?? false)
        );

        return response()->json(['message' => 'Vehicle deleted']);
    }

    public function createCommercialDocument(CreateCommercialDocumentRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $workshopMultiVehicleEnabled = $this->isWorkshopMultiVehicleEnabledForRequest($request, $companyId)
            && $this->tableExists('sales.customer_vehicles');

        try {
            $prepared = $this->prepareCreateCommercialDocumentUseCase->execute(
                $authUser,
                $payload,
                $companyId,
                $workshopMultiVehicleEnabled
            );

            $command = $this->createCommercialDocumentCommandFactory->fromPreparedPayload(
                $authUser,
                $prepared['payload'],
                $companyId,
                $prepared['branch_id'],
                $prepared['warehouse_id'],
                $prepared['cash_register_id']
            );

            $result = $this->createCommercialDocumentUseCase->executeCommand($command);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Commercial document created',
            'data' => $result,
        ], 201);
    }

    public function updateCommercialDocument(UpdateCommercialDocumentRequest $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $documentId = (int) $id;
        $payload = $request->validated();

        try {
            $preparedPayload = $this->prepareUpdateCommercialDocumentUseCase->execute($payload);

            $result = $this->updateCommercialDocumentDraftUseCase->execute($authUser, $companyId, $documentId, $preparedPayload);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Documento comercial actualizado',
            'data' => $result,
        ]);
    }

    public function voidCommercialDocument(VoidCommercialDocumentRequest $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $documentId = (int) $id;

        $payload = $request->validated();

        $featureBranchId = $this->salesLookupService->findCommercialDocumentBranchId($companyId, $documentId);

        $requireVoidPassword = $this->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $featureBranchId,
            'SALES_VOID_REQUIRE_PASSWORD',
            false
        );

        try {
            $preparedPayload = $this->prepareVoidCommercialDocumentUseCase->execute($authUser, $payload, $requireVoidPassword);
            $result = $this->voidCommercialDocumentUseCase->execute($authUser, $companyId, $documentId, $preparedPayload);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Documento comercial anulado',
            'data' => $result,
        ]);
    }

    public function commercialDocuments(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchIdFilter = $request->query('branch_id', $authUser->branch_id);
        $resolvedBranchId = ($branchIdFilter !== null && $branchIdFilter !== '') ? (int) $branchIdFilter : null;
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));
        $isSellerUser = $this->isSellerUserRole($roleCode, $roleProfile);
        $isAdminUser = $this->isAdminUserRole($roleCode, $roleProfile);
        // In SELLER_TO_CASHIER mode a cashier must see pending pre-documents from ALL
        // sellers in the branch, not only their own.
        $isCashierUser = $this->isCashierUserRole($roleCode, $roleProfile);
        $conversionStateParam = strtoupper(trim((string) ($request->query('conversion_state', ''))));
        $cashierBranchScope = $isCashierUser && $conversionStateParam === 'PENDING';
        $canViewAllSellersInPendingQueue = !$isSellerUser && $cashierBranchScope;
        $sellerToCashierEnabled = $this->resolveFeatureResolutionForContext(
            $companyId, $resolvedBranchId, 'SALES_SELLER_TO_CASHIER', false
        )['is_enabled'];

        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, $resolvedBranchId)
            && $this->tableExists('sales.customer_vehicles');

        $filters = [
            'branch_id' => $branchIdFilter,
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'source_origin' => $request->query('source_origin'),
            'document_kind' => $request->query('document_kind'),
            'document_kind_id' => $request->query('document_kind_id'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'customer_id' => $request->query('customer_id'),
            'vehicle' => trim((string) $request->query('vehicle', '')),
            'customer_vehicle_id' => $request->query('customer_vehicle_id'),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
            // Admin: no filter. Cashier viewing pending orders in separated mode: no filter (sees all sellers).
            // Everyone else (seller, etc.): restricted to own documents.
            'seller_user_id' => ((!$isSellerUser && $isAdminUser) || ($sellerToCashierEnabled && $canViewAllSellersInPendingQueue))
                ? null
                : (int) $authUser->id,
            'workshop_vehicle_search_enabled' => $workshopVehicleSearchEnabled,
        ];
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('per_page', $request->query('limit', 10));

        if ($page < 1) {
            $page = 1;
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $result = $this->salesLookupService->paginateCommercialDocuments($companyId, $filters, $page, $limit);

        return response()->json($result);
    }

    public function exportCommercialDocuments(Request $request)
    {
        //return response()->json($request->all());    
        //dd($request);
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchIdFilter = $request->query('branch_id', $authUser->branch_id);
        $resolvedBranchId = ($branchIdFilter !== null && $branchIdFilter !== '') ? (int) $branchIdFilter : null;
        $format = strtolower(trim((string) $request->query('format', 'csv')));
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));
        $isSellerUser = $this->isSellerUserRole($roleCode, $roleProfile);
        $isAdminUser = $this->isAdminUserRole($roleCode, $roleProfile);
        $workshopVehicleSearchEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, $resolvedBranchId)
            && $this->tableExists('sales.customer_vehicles');

        $filters = [
            'branch_id' => $branchIdFilter,
            'warehouse_id' => $request->query('warehouse_id'),
            'cash_register_id' => $request->query('cash_register_id'),
            'source_origin' => $request->query('source_origin'),
            'document_kind' => $request->query('document_kind'),
            'document_kind_id' => $request->query('document_kind_id'),
            'status' => $request->query('status'),
            'conversion_state' => $request->query('conversion_state'),
            'customer' => trim((string) $request->query('customer', '')),
            'customer_id' => $request->query('customer_id'),
            'vehicle' => trim((string) $request->query('vehicle', '')),
            'customer_vehicle_id' => $request->query('customer_vehicle_id'),
            'issue_date_from' => $request->query('issue_date_from'),
            'issue_date_to' => $request->query('issue_date_to'),
            'series' => trim((string) $request->query('series', '')),
            'number' => trim((string) $request->query('number', '')),
            'seller_user_id' => (!$isSellerUser && $isAdminUser) ? null : (int) $authUser->id,
            'workshop_vehicle_search_enabled' => $workshopVehicleSearchEnabled,
        ];

        //return response()->json($filters);
        $detailMode = strtoupper(trim((string) $request->query('detail', 'SUMMARY')));

        $max = (int) $request->query('max', 5000);
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 20000) {
            $max = 20000;
        }

        if ($detailMode === 'PRODUCT') {
            $detailRows = $this->salesLookupService->listCommercialDocumentProductsForExport($companyId, $filters, $max);

            if ($format === 'json') {
                return response()->json([
                    'data' => $detailRows,
                    'meta' => [
                        'count' => (int) $detailRows->count(),
                        'max' => $max,
                        'detail' => 'PRODUCT',
                    ],
                ]);
            }

            $filename = 'reporte_ventas_producto_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($detailRows) {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }

                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [
                    'ID',
                    'Documento',
                    'Serie',
                    'Numero',
                    'Solicita',
                    'Emite',
                    'Actor',
                    'Fecha Emision',
                    'Cliente',
                    'Vehiculo',
                    'Forma de Pago',
                    'Estado',
                    'Estado SUNAT',
                    'Estado Baja SUNAT',
                    'Producto',
                    'Unidad',
                    'Cantidad',
                    'Precio Unitario',
                    'Total Linea',
                    'SENATI'
                ], ';');

                foreach ($detailRows as $row) {
                    $issuer = trim((string) ($row->created_by_user_name ?? ''));
                    $seller = trim((string) ($row->origin_seller_user_name ?? ''));
                    $actor = ($seller !== '' && $issuer !== '' && strtoupper($seller) !== strtoupper($issuer))
                        ? ('Solicita: ' . $seller . ' | Emite: ' . $issuer)
                        : ($issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'));

                    fputcsv($out, [
                        (int) $row->id,
                        (string) ($row->document_kind_label ?? $row->document_kind),
                        (string) $row->series,
                        (string) $row->number,
                        $seller !== '' ? $seller : ($issuer !== '' ? $issuer : '-'),
                        $issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'),
                        $actor,
                        $row->issue_at ? (string) $row->issue_at : '',
                        (string) ($row->customer_name ?? ''),
                        trim(implode(' | ', array_values(array_filter([
                            (string) ($row->vehicle_plate_snapshot ?? ''),
                            (string) ($row->vehicle_brand_snapshot ?? ''),
                            (string) ($row->vehicle_model_snapshot ?? ''),
                        ], static function ($part) {
                            return trim($part) !== '';
                        })))),
                        (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                        (string) ($row->status_label ?? $row->status),
                        (string) ($row->sunat_status ?? ''),
                        (string) ($row->sunat_void_status ?? ''),
                        (string) ($row->product_description ?? ''),
                        (string) ($row->unit_code ?? '-'),
                        number_format((float) ($row->qty ?? 0), 3, '.', ''),
                        number_format((float) ($row->unit_price ?? 0), 2, '.', ''),
                        number_format((float) ($row->line_total ?? 0), 2, '.', ''),
                        number_format((float) ($row->igv ?? 0), 2, '.', ''),
                    ], ';');
                }

                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $rows = $this->salesLookupService->listCommercialDocumentsForExport($companyId, $filters, $max);

        if ($format === 'json') {
            return response()->json([
                'data' => $rows,
                'meta' => [
                    'count' => (int) $rows->count(),
                    'max' => $max,
                ],
            ]);
        }

        $filename = 'reporte_ventas_1' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID',
                'Documento',
                'Serie',
                'Numero',
                'Documento Afectado',
                'Solicita',
                'Emite',
                'Actor',
                'Fecha Emision',
                'Cliente',
                'Forma de Pago',
                'Estado',
                'Estado SUNAT',
                'Estado Baja SUNAT',
                'Descuento Item',
                'Descuento Global',
                'Total',
                'Saldo',
                'SENATI',
            ], ';');

            foreach ($rows as $row) {
                $issuer = trim((string) ($row->created_by_user_name ?? ''));
                $seller = trim((string) ($row->origin_seller_user_name ?? ''));
                $actor = ($seller !== '' && $issuer !== '' && strtoupper($seller) !== strtoupper($issuer))
                    ? ('Solicita: ' . $seller . ' | Emite: ' . $issuer)
                    : ($issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'));

                fputcsv($out, [
                    (int) $row->id,
                    (string) ($row->document_kind_label ?? $row->document_kind),
                    (string) $row->series,
                    (string) $row->number,
                    trim((string) (($row->source_document_kind ?? '') !== ''
                        ? (($row->source_document_kind ?? '') . ' ' . ($row->source_document_number ?? ''))
                        : ($row->source_document_number ?? ''))),
                    $seller !== '' ? $seller : ($issuer !== '' ? $issuer : '-'),
                    $issuer !== '' ? $issuer : ($seller !== '' ? $seller : '-'),
                    $actor,
                    $row->issue_at ? (string) $row->issue_at : '',
                    (string) ($row->customer_name ?? ''),
                    (string) ($row->payment_method_name ?? 'Sin metodo de pago'),
                    (string) ($row->status_label ?? $row->status),
                    (string) ($row->sunat_status ?? ''),
                    (string) ($row->sunat_void_status ?? ''),
                    number_format((float) ($row->item_discount_total ?? 0), 2, '.', ''),
                    number_format((float) ($row->global_discount_total ?? 0), 2, '.', ''),
                    number_format((float) ($row->total ?? 0), 2, '.', ''),
                    number_format((float) ($row->balance_due ?? 0), 2, '.', ''),
                    number_format((float) ($row->igv ?? 0), 2, '.', ''),
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function applyCommercialDocumentFilters($query, array $filters): void
    {
        // Filtering is delegated to repository-level methods.
    }

    public function convertCommercialDocument(ConvertCommercialDocumentRequest $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $sourceId = (int) $id;
        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        $source = $this->salesDocumentConversionService->findSourceDocument($companyId, $sourceId);

        if (!$source) {
            return response()->json([
                'message' => 'Documento origen no encontrado',
            ], 404);
        }

        if (!in_array((string) $source->document_kind, ['QUOTATION', 'SALES_ORDER'], true)) {
            return response()->json([
                'message' => 'Solo se puede convertir cotizacion o pedido de venta',
            ], 422);
        }

        $sourceBranchId = $source->branch_id !== null ? (int) $source->branch_id : null;
        $sellerToCashierEnabled = $this->isCommerceFeatureEnabledForContext($companyId, $sourceBranchId, 'SALES_SELLER_TO_CASHIER');

        if ($sellerToCashierEnabled && !$this->isCashierActor($roleProfile, $roleCode)) {
            return response()->json([
                'message' => 'Solo caja puede convertir pedidos en este modo de venta.',
            ], 403);
        }

        if ($sellerToCashierEnabled
            && $this->isCashierActor($roleProfile, $roleCode)
            && (!isset($payload['cash_register_id']) || (int) $payload['cash_register_id'] <= 0)) {
            return response()->json([
                'message' => 'Debes seleccionar la estacion de caja activa antes de convertir en este modo.',
            ], 422);
        }
        try {
            $prepared = $this->prepareConvertCommercialDocumentUseCase->execute(
                $source,
                $payload,
                $companyId,
                $sourceId,
                $sellerToCashierEnabled
            );
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        try {
            $command = $this->createCommercialDocumentCommandFactory->fromPreparedPayload(
                $authUser,
                $prepared['forward_payload'],
                $companyId,
                $prepared['forward_payload']['branch_id'] ?? null,
                $prepared['forward_payload']['warehouse_id'] ?? null,
                $prepared['forward_payload']['cash_register_id'] ?? null
            );

            $result = $this->createCommercialDocumentUseCase->executeCommand($command);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Commercial document created',
            'data' => $result,
        ], 201);
    }

    public function showCommercialDocument(Request $request, $id)
    {
        $companyId = (int) ($request->attributes->get('resolved_company_id') ?? $request->query('company_id', 0));
        $documentId = (int) $id;

        $doc = $this->salesDocumentReadService->findDocumentForShow($companyId, $documentId);

        if (!$doc) {
            return response()->json([
                'message' => 'Documento no encontrado',
            ], 404);
        }

        $items = $this->salesDocumentReadService->resolveDocumentItemsWithFallback($companyId, $documentId);
        $itemIds = $items->pluck('id')->map(function ($rowId) {
            return (int) $rowId;
        })->values()->all();
        $lotsByItem = $this->salesDocumentReadService->getLotsGroupedByItemIds($itemIds);

        $allTaxCategories = $this->resolveTaxCategories($companyId);

        $mappedItems = $items->map(function ($item) use ($allTaxCategories, $lotsByItem) {
            $taxCat = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = is_array($taxCat) ? (string) ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV';
            $taxRate = is_array($taxCat) ? (float) ($taxCat['rate_percent'] ?? 0) : 0;
            $itemLots = $lotsByItem->get((int) $item->id, collect())->map(function ($lot) {
                return [
                    'lot_id' => (int) $lot->lot_id,
                    'qty' => (float) $lot->qty,
                ];
            })->values();
            $itemMetadata = null;

            if ($item->metadata !== null && $item->metadata !== '') {
                $decodedMetadata = json_decode((string) $item->metadata, true);
                if (is_array($decodedMetadata)) {
                    $itemMetadata = $decodedMetadata;
                }
            }

            $productCode = trim((string) ($item->product_code ?? ''));
            if ($productCode === '' && is_array($itemMetadata)) {
                $productCode = trim((string) (
                    $itemMetadata['product_code']
                    ?? $itemMetadata['productCode']
                    ?? $itemMetadata['code']
                    ?? ''
                ));
            }
            if ($productCode === '' && $item->product_id !== null) {
                $productCode = 'ID-' . (int) $item->product_id;
            }

            return [
                'lineNo' => (int) $item->line_no,
                'productId' => $item->product_id !== null ? (int) $item->product_id : null,
                'productCode' => $productCode !== '' ? $productCode : null,
                'unitId' => $item->unit_id !== null ? (int) $item->unit_id : null,
                'priceTierId' => $item->price_tier_id !== null ? (int) $item->price_tier_id : null,
                'qty' => (float) $item->qty,
                'qtyBase' => (float) ($item->qty_base ?? 0),
                'conversionFactor' => (float) ($item->conversion_factor ?? 1),
                'baseUnitPrice' => (float) ($item->base_unit_price ?? 0),
                'unitLabel' => (string) ($item->unit_code ?? ''),
                'description' => (string) $item->description,
                'unitPrice' => (float) $item->unit_price,
                'unitCost' => (float) ($item->unit_cost ?? 0),
                'wholesaleDiscountPercent' => (float) ($item->wholesale_discount_percent ?? 0),
                'priceSource' => $item->price_source ?: 'MANUAL',
                'discountTotal' => (float) ($item->discount_total ?? 0),
                'lineTotal' => (float) $item->total,
                'taxCategoryId' => $item->tax_category_id,
                'taxLabel' => $taxLabel,
                'taxRate' => $taxRate,
                'taxAmount' => (float) $item->tax_total,
                'metadata' => $itemMetadata,
                'lots' => $itemLots,
            ];
        })->values();

        $docMetadata = [];
        if ($doc->metadata !== null && $doc->metadata !== '') {
            $decodedDocMetadata = json_decode((string) $doc->metadata, true);
            if (is_array($decodedDocMetadata)) {
                $docMetadata = $decodedDocMetadata;
            }
        }

        $gravadaTotal = 0;
        $inafectaTotal = 0;
        $exoneradaTotal = 0;
        $taxTotal = 0;

        foreach ($items as $item) {
            $taxCat = $item->tax_category_id ? $allTaxCategories->firstWhere('id', $item->tax_category_id) : null;
            $taxLabel = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['label'] ?? 'Sin IGV') : 'Sin IGV')));
            $taxCode = strtoupper(trim((string) (is_array($taxCat) ? ($taxCat['code'] ?? '') : '')));
            $taxRate = (float) (is_array($taxCat) ? ($taxCat['rate_percent'] ?? 0) : 0);
            $itemSubtotal = (float) ($item->subtotal ?? 0);
            $itemTaxTotal = (float) ($item->tax_total ?? 0);

            $isGravada = $itemTaxTotal > 0.00001
                || $taxRate > 0.00001
                || in_array($taxCode, ['10', '1000', 'IGV', 'VAT', 'GRAVADA'], true)
                || strpos($taxLabel, 'IGV') !== false
                || strpos($taxLabel, 'GRAV') !== false;

            $isExonerada = in_array($taxCode, ['20', '9997', 'EXONERADA'], true)
                || strpos($taxLabel, 'EXONER') !== false;

            $isInafecta = in_array($taxCode, ['30', '9998', 'INAFECTA'], true)
                || strpos($taxLabel, 'INAFECT') !== false;

            if ($isGravada) {
                $gravadaTotal += $itemSubtotal;
            } elseif ($isExonerada) {
                $exoneradaTotal += $itemSubtotal;
            } elseif ($isInafecta) {
                $inafectaTotal += $itemSubtotal;
            }

            $taxTotal += $itemTaxTotal;
        }

        if ($taxTotal <= 0.00001 && isset($doc->tax_total)) {
            $taxTotal = (float) ($doc->tax_total ?? 0);
        }

        if ($gravadaTotal <= 0.00001 && $taxTotal > 0.00001) {
            $docSubtotal = (float) ($doc->subtotal ?? 0);
            $gravadaTotal = max(0, $docSubtotal - $inafectaTotal - $exoneradaTotal);
        }

        $dueDate = null;
        if ($doc->due_at) {
            $dueText = trim((string) $doc->due_at);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dueText, $matches) === 1) {
                $dueDate = $matches[1];
            } else {
                $dueDate = $dueText;
            }
        }

        $vehiclePlateSnapshot = trim((string) (
            $doc->vehicle_plate_snapshot
            ?? $docMetadata['vehicle_plate']
            ?? $docMetadata['vehiclePlateSnapshot']
            ?? ''
        ));
        $vehicleBrandSnapshot = trim((string) (
            $doc->vehicle_brand_snapshot
            ?? $docMetadata['vehicle_brand']
            ?? $docMetadata['vehicleBrand']
            ?? ''
        ));
        $vehicleModelSnapshot = trim((string) (
            $doc->vehicle_model_snapshot
            ?? $docMetadata['vehicle_model']
            ?? $docMetadata['vehicleModel']
            ?? ''
        ));

        $customerVehicleId = $doc->customer_vehicle_id !== null ? (int) $doc->customer_vehicle_id : 0;
        if ($customerVehicleId <= 0) {
            $metadataCustomerVehicleId = $docMetadata['customer_vehicle_id'] ?? $docMetadata['customerVehicleId'] ?? null;
            if (is_numeric($metadataCustomerVehicleId)) {
                $customerVehicleId = (int) $metadataCustomerVehicleId;
            }
        }

        $branchId = $doc->branch_id !== null ? (int) $doc->branch_id : null;
        $workshopMultiVehicleEnabled = $this->isWorkshopMultiVehicleEnabledForContext($companyId, $branchId)
            && $this->tableExists('sales.customer_vehicles');

        if ($workshopMultiVehicleEnabled && $customerVehicleId > 0) {
            $needsVehicleFallback = $vehiclePlateSnapshot === '' || $vehicleBrandSnapshot === '' || $vehicleModelSnapshot === '';
            if ($needsVehicleFallback) {
                $vehicle = $this->customerVehicleService->findVehicleSnapshotById(
                    $companyId,
                    (int) $doc->customer_id,
                    $customerVehicleId
                );

                if ($vehicle) {
                    if ($vehiclePlateSnapshot === '') {
                        $vehiclePlateSnapshot = strtoupper(trim((string) ($vehicle->plate ?? '')));
                    }
                    if ($vehicleBrandSnapshot === '') {
                        $vehicleBrandSnapshot = trim((string) ($vehicle->brand ?? ''));
                    }
                    if ($vehicleModelSnapshot === '') {
                        $vehicleModelSnapshot = trim((string) ($vehicle->model ?? ''));
                    }
                }
            }
        }

        $payments = DB::table('sales.commercial_document_payments as p')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'p.payment_method_id')
            ->where('p.document_id', (int) $doc->id)
            ->orderBy('p.id')
            ->get([
                'p.payment_method_id',
                'p.amount',
                'p.status',
                'p.paid_at',
                'p.due_at',
                'p.notes',
                'pm.name as payment_method_name',
            ])
            ->map(function ($row) {
                return [
                    'payment_method_id' => $row->payment_method_id !== null ? (int) $row->payment_method_id : null,
                    'payment_method_name' => $row->payment_method_name !== null ? trim((string) $row->payment_method_name) : null,
                    'amount' => round((float) ($row->amount ?? 0), 2),
                    'status' => strtoupper(trim((string) ($row->status ?? 'PENDING'))),
                    'paid_at' => $row->paid_at !== null ? (string) $row->paid_at : null,
                    'due_at' => $row->due_at !== null ? (string) $row->due_at : null,
                    'notes' => $row->notes !== null ? trim((string) $row->notes) : null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'id' => (int) $doc->id,
                'branchId' => $doc->branch_id !== null ? (int) $doc->branch_id : null,
                'warehouseId' => $doc->warehouse_id !== null ? (int) $doc->warehouse_id : null,
                'customerId' => (int) $doc->customer_id,
                'customerVehicleId' => $customerVehicleId > 0 ? $customerVehicleId : null,
                'currencyId' => (int) $doc->currency_id,
                'paymentMethodId' => $doc->payment_method_id !== null ? (int) $doc->payment_method_id : null,
                'documentKind' => (string) $doc->document_kind,
                'series' => (string) $doc->series,
                'number' => (int) $doc->number,
                'issueDate' => (string) ($doc->issue_at ?? ''),
                'dueDate' => $dueDate,
                'status' => (string) $doc->status,
                'currencyCode' => (string) ($doc->currency_code ?? 'PEN'),
                'currencySymbol' => (string) ($doc->currency_symbol ?? 'S/'),
                'paymentMethodName' => (string) ($doc->payment_method_name ?? '-'),
                'customerName' => (string) ($doc->customer_name ?? '-'),
                'customerDocNumber' => (string) ($doc->customer_doc_number ?? '-'),
                'customerAddress' => (string) ($doc->customer_address ?? '-'),
                'customerPhone' => (string) (
                    $doc->customer_phone
                    ?? ($docMetadata['customer_phone'] ?? $docMetadata['customerPhone'] ?? '')
                ),
                'customerEmail' => (string) ($doc->customer_email ?? ''),
                'notes' => isset($doc->notes) ? (trim((string) $doc->notes) !== '' ? (string) $doc->notes : null) : null,
                'subtotal' => (float) (($doc->subtotal ?? 0) ?: ($gravadaTotal + $inafectaTotal + $exoneradaTotal)),
                'taxTotal' => (float) $taxTotal,
                'grandTotal' => (float) $doc->total,
                'metadata' => $docMetadata,
                'payments' => $payments,
                'vehiclePlateSnapshot' => $vehiclePlateSnapshot !== '' ? $vehiclePlateSnapshot : null,
                'vehicleBrandSnapshot' => $vehicleBrandSnapshot !== '' ? $vehicleBrandSnapshot : null,
                'vehicleModelSnapshot' => $vehicleModelSnapshot !== '' ? $vehicleModelSnapshot : null,
                'gravadaTotal' => (float) $gravadaTotal,
                'inafectaTotal' => (float) $inafectaTotal,
                'exoneradaTotal' => (float) $exoneradaTotal,
                'company' => $this->resolveCompanyPrintProfile($companyId),
                'items' => $mappedItems,
            ],
        ]);
    }

    public function commercialDocumentShareLink(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $format = strtolower(trim((string) $request->query('format', 'a4')));
        if (!in_array($format, ['a4', 'ticket'], true)) {
            $format = 'a4';
        }

        $ttlDays = (int) env('COMMERCIAL_DOCUMENT_SHARE_LINK_TTL_DAYS', 7);
        if ($ttlDays <= 0) {
            $ttlDays = 7;
        }

        $expiresAt = now()->addDays($ttlDays);
        $url = URL::temporarySignedRoute(
            'sales.commercial-documents.public-pdf',
            $expiresAt,
            [
                'id' => $id,
                'company_id' => $companyId,
                'format' => $format,
            ]
        );
        $url = $this->normalizePublicShareUrlScheme((string) $url, $request);

        return response()->json([
            'data' => [
                'url' => $url,
                'expiresAt' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    public function sendCommercialDocumentShareEmail(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        if ($companyId <= 0) {
            return response()->json([
                'message' => 'No se pudo resolver la empresa para el envío.',
            ], 422);
        }

        $validated = $request->validate([
            'to_email' => ['required', 'email', 'max:190'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:4000'],
            'format' => ['nullable', 'in:a4,ticket'],
        ]);

        $format = strtolower(trim((string) ($validated['format'] ?? 'a4')));
        if (!in_array($format, ['a4', 'ticket'], true)) {
            $format = 'a4';
        }

        $ttlDays = (int) env('COMMERCIAL_DOCUMENT_SHARE_LINK_TTL_DAYS', 7);
        if ($ttlDays <= 0) {
            $ttlDays = 7;
        }

        $expiresAt = now()->addDays($ttlDays);
        $pdfUrl = URL::temporarySignedRoute(
            'sales.commercial-documents.public-pdf',
            $expiresAt,
            [
                'id' => $id,
                'company_id' => $companyId,
                'format' => $format,
            ]
        );
        $pdfUrl = $this->normalizePublicShareUrlScheme((string) $pdfUrl, $request);

        $detailsResponse = $this->showCommercialDocument($request, $id);
        if ($detailsResponse->getStatusCode() >= 400) {
            return $detailsResponse;
        }

        $payload = $detailsResponse->getData(true);
        $doc = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        if (!$doc) {
            return response()->json([
                'message' => 'No se pudo obtener el comprobante para compartir.',
            ], 422);
        }

        $series = trim((string) ($doc['series'] ?? ''));
        $number = trim((string) ($doc['number'] ?? ''));
        $docKind = trim((string) ($doc['documentKind'] ?? 'Comprobante'));
        $docLabel = trim($docKind . ' ' . ($series !== '' ? $series : '-') . '-' . ($number !== '' ? $number : '0'));

        $company = $this->resolveCompanyPrintProfile($companyId);
        $smtpProfile = $this->resolveCompanySmtpProfile($companyId);
        $companyName = trim((string) (
            $smtpProfile['from_name']
            ?? $company['trade_name']
            ?? $company['legal_name']
            ?? config('mail.from.name', config('app.name', 'Facturacion'))
        ));
        $configuredFromEmail = trim((string) ($smtpProfile['from_email'] ?? $company['email'] ?? ''));
        $defaultFromEmail = trim((string) config('mail.from.address', ''));
        $fromEmail = filter_var($configuredFromEmail, FILTER_VALIDATE_EMAIL)
            ? $configuredFromEmail
            : $defaultFromEmail;

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'No hay correo remitente configurado para la empresa.',
            ], 422);
        }

        $toEmail = trim((string) $validated['to_email']);
        $subject = trim((string) ($validated['subject'] ?? ''));
        if ($subject === '') {
            $subject = $docLabel . ' emitido';
        }

        $customMessage = trim((string) ($validated['message'] ?? ''));
        $bodyText = $customMessage !== ''
            ? $customMessage
            : "Hola,\n\nTe compartimos tu {$docLabel}.\n\nDescarga PDF: {$pdfUrl}\n\nGracias por tu compra.";

        $shouldUseCustomSmtp = $smtpProfile['host'] !== '' && $smtpProfile['port'] > 0;
        $originalMailConfig = [
            'driver' => config('mail.driver'),
            'host' => config('mail.host'),
            'port' => config('mail.port'),
            'encryption' => config('mail.encryption'),
            'username' => config('mail.username'),
            'password' => config('mail.password'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];

        if ($shouldUseCustomSmtp) {
            config([
                'mail.driver' => 'smtp',
                'mail.host' => $smtpProfile['host'],
                'mail.port' => $smtpProfile['port'],
                'mail.encryption' => $smtpProfile['encryption'],
                'mail.username' => $smtpProfile['username'] !== '' ? $smtpProfile['username'] : null,
                'mail.password' => $smtpProfile['password'] !== '' ? $smtpProfile['password'] : null,
                'mail.from.address' => $fromEmail,
                'mail.from.name' => $companyName !== '' ? $companyName : config('app.name', 'Facturacion'),
            ]);
        }

        try {
            Mail::html(
                nl2br($this->escapeHtml($bodyText), false),
                function ($message) use ($toEmail, $fromEmail, $companyName, $subject) {
                    $message
                        ->to($toEmail)
                        ->from($fromEmail, $companyName !== '' ? $companyName : null)
                        ->subject($subject);
                }
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'No se pudo enviar el correo de compartido.',
                'error' => $e->getMessage(),
            ], 422);
        } finally {
            if ($shouldUseCustomSmtp) {
                config([
                    'mail.driver' => $originalMailConfig['driver'],
                    'mail.host' => $originalMailConfig['host'],
                    'mail.port' => $originalMailConfig['port'],
                    'mail.encryption' => $originalMailConfig['encryption'],
                    'mail.username' => $originalMailConfig['username'],
                    'mail.password' => $originalMailConfig['password'],
                    'mail.from.address' => $originalMailConfig['from_address'],
                    'mail.from.name' => $originalMailConfig['from_name'],
                ]);
            }
        }

        return response()->json([
            'message' => 'Correo enviado correctamente.',
            'data' => [
                'to' => $toEmail,
                'from' => $fromEmail,
                'subject' => $subject,
                'url' => $pdfUrl,
                'expiresAt' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    private function normalizePublicShareUrlScheme(string $url, Request $request): string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || stripos($trimmed, 'http://') !== 0) {
            return $trimmed;
        }

        if (app()->environment('local', 'development', 'testing')) {
            return $trimmed;
        }

        $forwardedProto = strtolower(trim((string) $request->header('x-forwarded-proto', '')));
        $host = strtolower(trim((string) parse_url($trimmed, PHP_URL_HOST)));
        $appUrlHost = strtolower(trim((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST)));
        $isRailwayHost = str_contains($host, '.up.railway.app') || str_contains($appUrlHost, '.up.railway.app');
        $isCloudHost = str_contains($host, 'fycticonsulting.com') || str_contains($appUrlHost, 'fycticonsulting.com');

        if ($request->isSecure() || $forwardedProto === 'https' || $isRailwayHost || $isCloudHost) {
            return 'https://' . ltrim(substr($trimmed, strlen('http://')), '/');
        }

        return $trimmed;
    }

    private function resolveCompanySmtpProfile(int $companyId): array
    {
        $empty = [
            'host' => '',
            'port' => 0,
            'encryption' => null,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
        ];

        if (!$this->tableExists('core.company_settings')) {
            return $empty;
        }

        $columns = $this->tableColumns('core.company_settings');
        if (!in_array('extra_data', $columns, true)) {
            return $empty;
        }

        $settings = $this->salesLookupService->findLatestCompanySettings(
            $companyId,
            ['extra_data'],
            false,
            in_array('updated_at', $columns, true),
            in_array('created_at', $columns, true)
        );

        if (!$settings || !isset($settings->extra_data)) {
            return $empty;
        }

        $extraData = json_decode((string) $settings->extra_data, true);
        if (!is_array($extraData)) {
            return $empty;
        }

        $host = trim((string) ($extraData['smtp_host'] ?? ''));
        $port = (int) ($extraData['smtp_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $port = 0;
        }

        $encryptionRaw = strtolower(trim((string) ($extraData['smtp_encryption'] ?? '')));
        $encryption = in_array($encryptionRaw, ['tls', 'ssl', 'starttls'], true)
            ? $encryptionRaw
            : null;

        $username = trim((string) ($extraData['smtp_username'] ?? ''));
        $password = '';
        $encryptedPassword = trim((string) ($extraData['smtp_password_enc'] ?? ''));
        if ($encryptedPassword !== '') {
            try {
                $password = (string) Crypt::decryptString($encryptedPassword);
            } catch (Throwable $e) {
                $password = '';
            }
        }

        $fromEmail = trim((string) ($extraData['smtp_from_email'] ?? ''));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = '';
        }

        $fromName = trim((string) ($extraData['smtp_from_name'] ?? ''));

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
    }

    public function publicPrintableCommercialDocumentPdf(Request $request, int $id)
    {
        $companyId = (int) $request->query('company_id', 0);
        if ($companyId <= 0) {
            return response()->json([
                'message' => 'company_id inválido para enlace público.',
            ], 422);
        }

        $request->attributes->set('resolved_company_id', $companyId);
        $request->attributes->set('is_public_pdf_link', true);
        return $this->printableCommercialDocumentPdf($request, $id);
    }

    public function printableCommercialDocument(Request $request, int $id)
    {
        $jsonResponse = $this->showCommercialDocument($request, $id);
        if ($jsonResponse->getStatusCode() >= 400) {
            return $jsonResponse;
        }

        $payload = $jsonResponse->getData(true);
        $doc = $payload['data'] ?? null;
        if (!is_array($doc)) {
            return response()->json([
                'message' => 'No se pudo generar la impresion del documento',
            ], 422);
        }

        $format = in_array($request->query('format'), ['ticket', 'a4'], true)
            ? (string) $request->query('format')
            : 'ticket';

        $html = $this->renderCommercialDocumentTicketHtml($doc, $format);
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function printableCommercialDocumentPdf(Request $request, int $id)
    {
        $jsonResponse = $this->showCommercialDocument($request, $id);
        if ($jsonResponse->getStatusCode() >= 400) {
            return $jsonResponse;
        }

        $payload = $jsonResponse->getData(true);
        $doc = $payload['data'] ?? null;
        if (!is_array($doc)) {
            return response()->json([
                'message' => 'No se pudo generar el PDF del documento',
            ], 422);
        }

        $isPublicPdfLink = (bool) $request->attributes->get('is_public_pdf_link', false);
        if ($isPublicPdfLink) {
            $doc['public_pdf_link'] = true;
        }

        $format = in_array($request->query('format'), ['ticket', 'a4'], true)
            ? (string) $request->query('format')
            : 'a4';

        $series = preg_replace('/[^A-Za-z0-9\-_]/', '', trim((string) ($doc['series'] ?? 'DOC')));
        $number = preg_replace('/[^0-9]/', '', trim((string) ($doc['number'] ?? '0')));
        $fileName = ($series !== '' ? $series : 'DOC') . '-' . ($number !== '' ? $number : '0') . '.pdf';

        $options = new Options();
        // Keep internal PDF generation local-only; public signed links can resolve remote frontend assets.
        $options->set('isRemoteEnabled', $isPublicPdfLink);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('defaultMediaType', 'print');
        $options->set('dpi', 96);

        $buildPdfOutput = function (array $pdfDoc) use ($options, $format, $isPublicPdfLink): string {
            $dompdf = new Dompdf($options);
            $html = $this->renderCommercialDocumentTicketHtml($pdfDoc, $format);
            if ($isPublicPdfLink && $format === 'a4') {
                $html = $this->applyPublicA4PdfLayoutAdjustments($html);
            }
            $dompdf->loadHtml($html, 'UTF-8');

            if ($format === 'ticket') {
                // 80mm width in points: 80 / 25.4 * 72 = 226.77
                // Height is intentionally tall to avoid clipping long tickets.
                $dompdf->setPaper([0, 0, 226.77, 1800], 'portrait');
            } else {
                $dompdf->setPaper('A4', 'portrait');
            }

            $dompdf->render();

            return $dompdf->output();
        };

        try {
            $pdfBinary = $buildPdfOutput($doc);
        } catch (\Throwable $e) {
            if (!$this->isDompdfGdImageFailure($e)) {
                throw $e;
            }

            Log::warning('PDF render fallback without logo due GD/image failure', [
                'document_id' => $id,
                'message' => $e->getMessage(),
            ]);

            $fallbackDoc = $doc;
            $company = is_array($fallbackDoc['company'] ?? null) ? $fallbackDoc['company'] : [];
            $company['logo_data_uri'] = null;
            $company['logoDataUri'] = null;
            $company['logo_url'] = null;
            $company['logoUrl'] = null;
            $fallbackDoc['company'] = $company;

            $pdfBinary = $buildPdfOutput($fallbackDoc);
        }

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function isDompdfGdImageFailure(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'gd extension is required')) {
            return true;
        }

        if (str_contains($message, 'addpngfromfile') || str_contains($message, 'png')) {
            return true;
        }

        return false;
    }

    private function applyPublicA4PdfLayoutAdjustments(string $html): string
    {
        return str_replace(
            [
                '@page { size: A4 portrait; margin: 8mm; }',
                'body { margin: 0; padding: 0 1mm; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 11px; }',
                '.sheet { width: 100%; max-width: 194mm; margin: 0 auto; border: 1px solid #111; padding: 5mm; }',
            ],
            [
                '@page { size: A4 portrait; margin: 6mm; }',
                'body { margin: 0; padding: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 11px; }',
                '.sheet { width: 100%; max-width: 197mm; margin: 0 auto; border: 1px solid #111; padding: 4mm; }',
            ],
            $html
        );
    }

        private function renderCommercialDocumentA4LegacyHtml(array $doc): string
        {
                $company = is_array($doc['company'] ?? null) ? $doc['company'] : [];
                $metaData = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];

                $companyTitle = trim((string) ($company['trade_name'] ?? $company['tradeName'] ?? $company['legal_name'] ?? $company['legalName'] ?? 'SISTEMA FACTURACION'));
                $companyLegalName = trim((string) ($company['legal_name'] ?? $company['legalName'] ?? $companyTitle));
                $companyDescription = trim((string) ($company['company_description'] ?? $company['companyDescription'] ?? ''));
                $companyTaxId = trim((string) ($company['tax_id'] ?? $company['taxId'] ?? '00000000000'));
                $companyAddress = trim((string) ($company['address'] ?? ''));
                $companyPhone = trim((string) ($company['phone'] ?? ''));
                $companyEmail = trim((string) ($company['email'] ?? ''));
                $logoDataUriRaw = trim((string) ($company['logo_data_uri'] ?? $company['logoDataUri'] ?? ''));
                $logoUrlRaw = trim((string) ($company['logo_url'] ?? $company['logoUrl'] ?? ''));
                $logoSourceRaw = trim((string) (
                    $company['logo_data_uri']
                    ?? $company['logoDataUri']
                    ?? $company['logo_url']
                    ?? $company['logoUrl']
                    ?? ''
                ));
                if (!function_exists('imagecreatetruecolor') && preg_match('/^data:image\//i', $logoDataUriRaw) === 1) {
                    $logoSourceRaw = $logoUrlRaw;
                }
                $logoUrl = $this->escapeHtml($logoSourceRaw);
                $companyId = (int) ($company['company_id'] ?? $company['id'] ?? 0);
                $branchId = isset($doc['branchId']) && $doc['branchId'] !== null
                    ? (int) $doc['branchId']
                    : null;
                $workshopMultiVehicleEnabled = $companyId > 0
                    ? $this->isWorkshopMultiVehicleEnabledForContext($companyId, $branchId)
                    : false;
                $salesOrderMultiPaymentEnabled = $companyId > 0
                    ? $this->isSalesOrderMultiPaymentEnabledForContext($companyId, $branchId)
                    : false;

                $docKindRaw = strtoupper(trim((string) ($doc['documentKind'] ?? 'DOCUMENTO')));
                $isSalesOrderDocument = $docKindRaw === 'SALES_ORDER';
                $docKindLabel = [
                        'INVOICE' => 'FACTURA ELECTRONICA',
                        'RECEIPT' => 'BOLETA ELECTRONICA',
                        'CREDIT_NOTE' => 'NOTA DE CREDITO',
                        'DEBIT_NOTE' => 'NOTA DE DEBITO',
                        'SALES_ORDER' => 'PEDIDO DE VENTA',
                        'QUOTATION' => 'COTIZACION',
                ][$docKindRaw] ?? ($docKindRaw !== '' ? $docKindRaw : 'DOCUMENTO');

                $series = $this->escapeHtml((string) ($doc['series'] ?? ''));
                $number = $this->escapeHtml((string) ($doc['number'] ?? '0'));
                $issueAtRaw = (string) ($doc['issueDate'] ?? '');
                $issueAt = $this->escapeHtml($this->formatIssueDateTime($issueAtRaw));
                $issueDateOnly = $issueAt;
                if ($issueAtRaw !== '') {
                        try {
                                $issueDateOnly = $this->escapeHtml(Carbon::parse($issueAtRaw)->format('d/m/Y'));
                        } catch (\Throwable $e) {
                                $issueDateOnly = $issueAt;
                        }
                }

                $dueDate = $this->findFirstMetaStringValue($metaData, ['due_date', 'fecha_vencimiento', 'dueDate']);
                if ($dueDate === '') {
                        $dueDate = $issueDateOnly;
                }

                $customer = $this->escapeHtml((string) ($doc['customerName'] ?? '-'));
                $customerDoc = $this->escapeHtml((string) ($doc['customerDocNumber'] ?? '-'));
                $customerAddress = $this->escapeHtml((string) ($doc['customerAddress'] ?? '-'));
                $customerPhone = trim((string) (
                    $doc['customerPhone']
                    ?? $metaData['customer_phone']
                    ?? $metaData['customerPhone']
                    ?? ''
                ));
                $customerPhoneEscaped = $this->escapeHtml($customerPhone !== '' ? $customerPhone : '-');
                $customerPhoneLine = $workshopMultiVehicleEnabled
                    ? '<div class="line"><span class="k">TELEFONO:</span><span class="v">' . $customerPhoneEscaped . '</span></div>'
                    : '';
                $documentNotes = trim((string) ($doc['notes'] ?? ''));
                $documentNotesLine = $documentNotes !== ''
                    ? '<div class="line"><span class="k">OBSERVACIONES:</span><span class="v">' . $this->escapeHtml($documentNotes) . '</span></div>'
                    : '';
                $paymentMethod = $this->escapeHtml((string) ($doc['paymentMethodName'] ?? '-'));
                $paymentBreakdownRows = is_array($metaData['payment_breakdown'] ?? null)
                    ? $metaData['payment_breakdown']
                    : [];
                $paymentBreakdownLines = '';
                foreach ($paymentBreakdownRows as $paymentRow) {
                    if (!is_array($paymentRow)) {
                        continue;
                    }

                    $amount = round((float) ($paymentRow['amount'] ?? 0), 2);
                    if ($amount <= 0) {
                        continue;
                    }

                    $methodName = trim((string) (
                        $paymentRow['payment_method_name']
                        ?? $paymentRow['method_name']
                        ?? $paymentRow['name']
                        ?? ''
                    ));
                    if ($methodName === '') {
                        $methodId = isset($paymentRow['payment_method_id']) ? (int) $paymentRow['payment_method_id'] : 0;
                        $methodName = $methodId > 0 ? ('Metodo #' . $methodId) : 'Metodo de pago';
                    }

                    $paymentBreakdownLines .= '<div class="line"><span class="k">PAGO ' . $this->escapeHtml($methodName) . ':</span><span class="v">' . $this->escapeHtml((string) ($doc['currencySymbol'] ?? 'S/')) . ' ' . $this->formatAmount($amount) . '</span></div>';
                }
                $hideGenericPaymentLine = $isSalesOrderDocument && $salesOrderMultiPaymentEnabled;
                $paymentConditionLine = $hideGenericPaymentLine
                    ? ''
                    : '<div class="line"><span class="k">COND. DE PAGO:</span><span class="v">' . $paymentMethod . '</span></div>';
                if ($hideGenericPaymentLine === false) {
                    $paymentBreakdownLines = '';
                }
                $currencyCode = strtoupper((string) ($doc['currencyCode'] ?? 'PEN'));
                $currency = $this->escapeHtml((string) ($doc['currencySymbol'] ?? ($currencyCode === 'PEN' ? 'S/' : $currencyCode)));
                $currencyLabel = $currencyCode === 'PEN' ? 'SOLES' : $currencyCode;

                $guideNo = $this->escapeHtml($this->findFirstMetaStringValue($metaData, ['guia', 'nro_guia', 'guide_number', 'guideNumber']));
                $seller = $this->escapeHtml($this->findFirstMetaStringValue($metaData, ['seller_name', 'vendedor', 'salesperson_name']));
                $orderPurchase = $this->escapeHtml($this->findFirstMetaStringValue($metaData, ['purchase_order', 'orden_compra', 'order_purchase']));
                $customerCode = $this->escapeHtml($this->findFirstMetaStringValue($metaData, ['customer_code', 'codigo_cliente', 'client_code']));
                $incoterm = $this->escapeHtml($this->findFirstMetaStringValue($metaData, ['incoterm']));
                $areaVta = $this->escapeHtml($this->findFirstMetaStringValue($metaData, ['area_vta', 'area_venta', 'sales_area']));
                $countryCode = $this->escapeHtml($this->findFirstMetaStringValue($metaData, ['country_code', 'codigo_pais']));
                if ($countryCode === '') {
                        $countryCode = 'PER';
                }

                $vehiclePlate = trim((string) (
                        $doc['vehiclePlateSnapshot']
                        ?? $metaData['vehicle_plate']
                        ?? $metaData['vehiclePlateSnapshot']
                        ?? ''
                ));
                $vehicleBrand = trim((string) (
                        $doc['vehicleBrandSnapshot']
                        ?? $metaData['vehicle_brand']
                        ?? $metaData['vehicleBrand']
                        ?? ''
                ));
                $vehicleModel = trim((string) (
                        $doc['vehicleModelSnapshot']
                        ?? $metaData['vehicle_model']
                        ?? $metaData['vehicleModel']
                        ?? ''
                ));
                $vehicleInfo = trim(implode(' ', array_filter([$vehiclePlate, $vehicleBrand, $vehicleModel], static fn ($v) => trim((string) $v) !== '')));
                $vehicleBlock = $workshopMultiVehicleEnabled && $vehicleInfo !== ''
                        ? '<div class="line"><span class="k">VEHICULO:</span><span class="v">' . $this->escapeHtml($vehicleInfo) . '</span></div>'
                        : '';

                $gravadaTotal = (float) ($doc['gravadaTotal'] ?? 0);
                $inafectaTotal = (float) ($doc['inafectaTotal'] ?? 0);
                $exoneradaTotal = (float) ($doc['exoneradaTotal'] ?? 0);
                $taxTotal = (float) ($doc['taxTotal'] ?? 0);
                $grandTotal = (float) ($doc['grandTotal'] ?? 0);
                $totalInWords = $this->escapeHtml($this->amountToSpanishWords($grandTotal, $currencyCode));

                $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
                $itemRows = '';
                foreach ($items as $item) {
                        $itemMetadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
                        $itemCodeRaw = trim((string) (
                                $item['productCode']
                                ?? $item['product_code']
                                ?? $itemMetadata['product_code']
                                ?? $itemMetadata['productCode']
                                ?? $itemMetadata['code']
                                ?? ''
                        ));
                        if ($itemCodeRaw === '' && !empty($item['productId'])) {
                                $itemCodeRaw = 'ID-' . (int) $item['productId'];
                        }

                        $unitPrice = (float) ($item['unitPrice'] ?? 0);
                        $salePrice = (float) ($item['salePrice'] ?? $item['priceWithTax'] ?? $unitPrice);
                        $discount = (float) ($item['discountTotal'] ?? $item['discount'] ?? 0);
                        $lineTotal = (float) ($item['lineTotal'] ?? 0);

                        $itemRows .= '<tr>'
                                . '<td class="c">' . (int) ($item['lineNo'] ?? 0) . '</td>'
                                . '<td class="c">' . ($itemCodeRaw !== '' ? $this->escapeHtml($itemCodeRaw) : '-') . '</td>'
                                . '<td class="r">' . $this->formatAmount((float) ($item['qty'] ?? 0)) . '</td>'
                                . '<td class="c">' . $this->escapeHtml((string) ($item['unitLabel'] ?? 'NIU')) . '</td>'
                                . '<td class="l">' . $this->escapeHtml((string) ($item['description'] ?? '-')) . '</td>'
                                . '<td class="r">' . $this->formatAmount($unitPrice) . '</td>'
                                . '<td class="r">' . $this->formatAmount($salePrice) . '</td>'
                                . '<td class="r">' . $this->formatAmount($discount) . '</td>'
                                . '<td class="r">' . $this->formatAmount($lineTotal) . '</td>'
                                . '</tr>';
                }
                if ($itemRows === '') {
                        $itemRows = '<tr><td colspan="9" class="c">SIN ITEMS</td></tr>';
                }

                $documentFileName = $this->escapeHtml(trim((string) ($doc['series'] ?? '')) . '-' . trim((string) ($doc['number'] ?? '')) . '.pdf');
                $companyTitleEsc = $this->escapeHtml($companyTitle !== '' ? $companyTitle : 'SISTEMA FACTURACION');
                $companyLegalBlock = ($companyLegalName !== '' && $companyLegalName !== $companyTitle)
                        ? '<div class="company-legal">' . $this->escapeHtml($companyLegalName) . '</div>'
                        : '';
                $companyDescriptionBlock = $companyDescription !== ''
                        ? '<div class="company-meta">' . $this->escapeHtml($companyDescription) . '</div>'
                        : '';
                $companyAddressBlock = $companyAddress !== ''
                        ? '<div class="company-meta">' . $this->escapeHtml($companyAddress) . '</div>'
                        : '';
                $companyPhoneBlock = $companyPhone !== ''
                        ? '<div class="company-meta">Central telefonica: ' . $this->escapeHtml($companyPhone) . '</div>'
                        : '';
                $companyEmailBlock = $companyEmail !== ''
                        ? '<div class="company-meta">' . $this->escapeHtml($companyEmail) . '</div>'
                        : '';
                $logoBlock = $logoUrl !== '' ? '<img src="' . $logoUrl . '" alt="Logo" class="logo" />' : '';

                $bankAccounts = is_array($company['bank_accounts'] ?? null)
                    ? $company['bank_accounts']
                    : (is_array($company['bankAccounts'] ?? null) ? $company['bankAccounts'] : []);
                $bankRows = '';
                foreach ($bankAccounts as $bank) {
                    if (!is_array($bank)) {
                        continue;
                    }
                    $bankName = $this->escapeHtml((string) ($bank['bank_name'] ?? ''));
                    $account = $this->escapeHtml((string) ($bank['account_number'] ?? ''));
                    $cci = $this->escapeHtml((string) ($bank['cci'] ?? ''));
                    $holder = $this->escapeHtml((string) ($bank['account_holder'] ?? ''));

                    if ($bankName === '' && $account === '' && $cci === '' && $holder === '') {
                        continue;
                    }

                    $bankRows .= '<div class="bank-item">';
                    if ($bankName !== '') {
                        $bankRows .= '<div><strong>' . $bankName . '</strong></div>';
                    }
                    if ($account !== '') {
                        $bankRows .= '<div>Cuenta: ' . $account . '</div>';
                    }
                    if ($cci !== '') {
                        $bankRows .= '<div>CCI: ' . $cci . '</div>';
                    }
                    if ($holder !== '') {
                        $bankRows .= '<div>Titular: ' . $holder . '</div>';
                    }
                    $bankRows .= '</div>';
                }
                $banksSection = $bankRows !== ''
                    ? '<div class="bank-box"><div class="bank-title">BANCOS</div>' . $bankRows . '</div>'
                    : '';

                $showPaymentBrandsRaw = $company['show_payment_brand_icons'] ?? $company['showPaymentBrandIcons'] ?? true;
                if ($showPaymentBrandsRaw === null) {
                    $showPaymentBrands = true;
                } else {
                    $showPaymentBrands = filter_var($showPaymentBrandsRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($showPaymentBrands === null) {
                        $showPaymentBrands = (bool) $showPaymentBrandsRaw;
                    }
                }

                $paymentBrandsSection = '';
                if ($showPaymentBrands) {
                    $yapeLogo = $this->escapeHtml($this->resolvePaymentBrandImageSource('yape-official.png'));
                    $plinLogo = $this->escapeHtml($this->resolvePaymentBrandImageSource('plin-official.png'));
                    $culqiLogo = $this->escapeHtml($this->resolvePaymentBrandImageSource('culqi-official.png'));

                    $paymentBrandsSection = '<div class="pay-logos">'
                        . '<div class="paybrand"><img src="' . $yapeLogo . '" alt="Yape" /></div>'
                        . '<div class="paybrand"><img src="' . $plinLogo . '" alt="Plin" /></div>'
                        . '<div class="paybrand"><img src="' . $culqiLogo . '" alt="Culqi" /></div>'
                        . '</div>';
                }

                $electronicSignatureRaw = $this->findFirstMetaStringValue($metaData, [
                    'sunat_electronic_signature',
                    'sunat_signature',
                    'firma_electronica',
                    'firma',
                    'signature',
                    'hash_cpe',
                    'codigo_hash',
                    'digest_value',
                    'digestValue',
                ]);
                $electronicSignatureRaw = $this->normalizeElectronicSignatureValue($electronicSignatureRaw);
                $electronicSignatureBlock = $electronicSignatureRaw !== ''
                    ? '<div class="sign-box"><strong>Firma electronica:</strong> ' . $this->escapeHtml($electronicSignatureRaw) . '</div>'
                    : '';

                $resolveTaxAccountNumber = function (array $metadata, string $flatKey, string $nestedKey): string {
                    $flat = trim((string) ($metadata[$flatKey] ?? ''));
                    if ($flat !== '') {
                        return $flat;
                    }

                    $nested = $metadata[$nestedKey] ?? null;
                    if (!is_array($nested)) {
                        return '';
                    }

                    return trim((string) ($nested['account_number'] ?? ''));
                };

                $tributaryAccountLines = [];
                if (!empty($metaData['has_detraccion'])) {
                    $account = $resolveTaxAccountNumber($metaData, 'detraccion_account_number', 'detraccion_account');
                    if ($account !== '') {
                        $tributaryAccountLines[] = 'Detraccion: ' . $this->escapeHtml($account);
                    }
                }
                if (!empty($metaData['has_retencion'])) {
                    $account = $resolveTaxAccountNumber($metaData, 'retencion_account_number', 'retencion_account');
                    if ($account !== '') {
                        $tributaryAccountLines[] = 'Retencion: ' . $this->escapeHtml($account);
                    }
                }
                if (!empty($metaData['has_percepcion'])) {
                    $account = $resolveTaxAccountNumber($metaData, 'percepcion_account_number', 'percepcion_account');
                    if ($account !== '') {
                        $tributaryAccountLines[] = 'Percepcion: ' . $this->escapeHtml($account);
                    }
                }

                $tributaryAccountBlock = count($tributaryAccountLines) > 0
                    ? '<div class="sign-box"><strong>Cuentas tributarias:</strong><br>' . implode('<br>', $tributaryAccountLines) . '</div>'
                    : '';

                return <<<HTML
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>{$documentFileName}</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0 1mm; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 11px; }
        .sheet { width: 100%; max-width: 194mm; margin: 0 auto; border: 1px solid #111; padding: 5mm; }
        .top-3 { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .top-3 td { vertical-align: top; }
        .top-logo { width: 19%; padding-right: 3mm; }
        .top-company { width: 53%; padding-right: 3mm; }
        .top-voucher { width: 28%; }
        .logo-wrap { min-height: 82px; display: flex; align-items: center; justify-content: center; }
        .logo { max-width: 100%; max-height: 86px; display: block; }
        .company-title { font-size: 18px; font-weight: 700; letter-spacing: 0.25px; text-transform: uppercase; margin-bottom: 3px; }
        .company-legal { font-size: 12px; margin-bottom: 2px; }
        .company-meta { font-size: 11px; line-height: 1.25; }
        .voucher-box { border: 1px solid #111; text-align: center; padding: 12px 8px; min-height: 112px; }
        .voucher-ruc { font-size: 12px; font-weight: 700; margin-bottom: 11px; letter-spacing: 0.6px; }
        .voucher-kind { font-size: 12px; font-weight: 700; margin-bottom: 11px; letter-spacing: 0.5px; text-transform: uppercase; }
        .voucher-no { font-size: 14px; font-weight: 700; letter-spacing: 0.8px; }
        .legend { text-align: center; font-size: 10px; margin: 5px 0 6px; }
        .info-box { border: 1px solid #111; margin-bottom: 4px; }
        .info-grid { width: 100%; border-collapse: collapse; }
        .info-grid td { width: 50%; vertical-align: top; padding: 4px 8px; }
        .line { margin: 2px 0; }
        .k { display: inline-block; width: 126px; font-weight: 700; letter-spacing: 0.3px; }
        .v { display: inline-block; }
        .items { width: 100%; border-collapse: collapse; margin-top: 2px; }
        .items th, .items td { border: 1px solid #111; padding: 4px 5px; }
        .items th { text-align: center; font-size: 11px; font-weight: 700; }
        .items td { font-size: 10.5px; }
        .l { text-align: left; }
        .c { text-align: center; }
        .r { text-align: right; }
        .totals-wrap { margin-top: 5px; display: table; width: 100%; }
        .words { display: table-cell; width: 64%; vertical-align: top; padding-right: 8px; font-size: 10.8px; }
        .totals { display: table-cell; width: 36%; vertical-align: top; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { border: 1px solid #111; padding: 3px 5px; font-size: 10.8px; }
        .totals .k2 { font-weight: 700; text-align: right; }
        .totals .v2 { text-align: right; }
        .totals .grand td { font-size: 12px; font-weight: 700; }
        .extras { margin-top: 4px; border-top: 1px solid #111; padding-top: 3px; }
        .bank-box { font-size: 10px; line-height: 1.2; margin-bottom: 3px; }
        .bank-title { font-weight: 700; margin-bottom: 2px; }
        .bank-item { margin-bottom: 2px; }
        .pay-logos { display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; margin-bottom: 3px; }
        .paybrand { border: 1px solid #111; border-radius: 2px; padding: 2px 4px; background: #fff; }
        .paybrand img { height: 20px; width: auto; display: block; }
        .sign-box { font-size: 10px; line-height: 1.25; margin-bottom: 3px; word-break: break-all; }
    </style>
</head>
<body>
    <section class="sheet">
        <table class="top-3">
            <tr>
                <td class="top-logo">
                    <div class="logo-wrap">{$logoBlock}</div>
                </td>
                <td class="top-company">
                    <div class="company-title">{$companyTitleEsc}</div>
                    {$companyLegalBlock}
                    {$companyDescriptionBlock}
                    {$companyAddressBlock}
                    {$companyPhoneBlock}
                    {$companyEmailBlock}
                </td>
                <td class="top-voucher">
                    <div class="voucher-box">
                        <div class="voucher-ruc">R.U.C. {$this->escapeHtml($companyTaxId)}</div>
                        <div class="voucher-kind">{$this->escapeHtml($docKindLabel)}</div>
                        <div class="voucher-no">{$series}-{$number}</div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="legend">Ano del Bicentenario, de la consolidacion de nuestra Independencia, y de la conmemoracion de las heroicas batallas de Junin y Ayacucho</div>

        <section class="info-box">
            <table class="info-grid">
                <tr>
                    <td>
                        <div class="line"><span class="k">R.U.C:</span><span class="v">{$customerDoc}</span></div>
                        <div class="line"><span class="k">SENOR(ES):</span><span class="v">{$customer}</span></div>
                        {$customerPhoneLine}
                        <div class="line"><span class="k">DIRECCION:</span><span class="v">{$customerAddress}</span></div>
                        {$documentNotesLine}
                        {$vehicleBlock}
                    </td>
                    <td>
                        <div class="line"><span class="k">FECHA EMISION:</span><span class="v">{$issueDateOnly}</span></div>
                        <div class="line"><span class="k">FECHA VENCIMIENTO:</span><span class="v">{$this->escapeHtml($dueDate)}</span></div>
                        <div class="line"><span class="k">TIPO DE MONEDA:</span><span class="v">{$this->escapeHtml($currencyLabel)}</span></div>
                        <div class="line"><span class="k">CODIGO DE PAIS:</span><span class="v">{$countryCode}</span></div>
                    </td>
                </tr>
            </table>
        </section>

        <section class="info-box">
            <table class="info-grid">
                <tr>
                    <td>
                        <div class="line"><span class="k">NRO GUIA:</span><span class="v">{$guideNo}</span></div>
                        {$paymentConditionLine}
                        <div class="line"><span class="k">VENDEDOR:</span><span class="v">{$seller}</span></div>
                        {$paymentBreakdownLines}
                    </td>
                    <td>
                        <div class="line"><span class="k">ORDEN COMPRA:</span><span class="v">{$orderPurchase}</span></div>
                        <div class="line"><span class="k">COD. CLIENTE:</span><span class="v">{$customerCode}</span></div>
                        <div class="line"><span class="k">INCOTERM:</span><span class="v">{$incoterm}</span></div>
                        <div class="line"><span class="k">AREA.VTA:</span><span class="v">{$areaVta}</span></div>
                    </td>
                </tr>
            </table>
        </section>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:5%">Item</th>
                    <th style="width:12%">Codigo</th>
                    <th style="width:8%">Cant.</th>
                    <th style="width:8%">Unid.</th>
                    <th style="width:33%">Descripcion</th>
                    <th style="width:10%">Valor Unit.</th>
                    <th style="width:10%">Precio Vta.</th>
                    <th style="width:7%">Dscto.</th>
                    <th style="width:7%">Valor Vta.</th>
                </tr>
            </thead>
            <tbody>
                {$itemRows}
            </tbody>
        </table>

        <section class="totals-wrap">
            <div class="words">
                <div><strong>SON:</strong> {$totalInWords}</div>
            </div>
            <div class="totals">
                <table>
                    <tr><td class="k2">OP. GRAVADAS</td><td class="v2">{$currency} {$this->formatAmount($gravadaTotal)}</td></tr>
                    <tr><td class="k2">OP. INAFECTAS</td><td class="v2">{$currency} {$this->formatAmount($inafectaTotal)}</td></tr>
                    <tr><td class="k2">OP. EXONERADAS</td><td class="v2">{$currency} {$this->formatAmount($exoneradaTotal)}</td></tr>
                    <tr><td class="k2">IGV</td><td class="v2">{$currency} {$this->formatAmount($taxTotal)}</td></tr>
                    <tr class="grand"><td class="k2">TOTAL</td><td class="v2">{$currency} {$this->formatAmount($grandTotal)}</td></tr>
                </table>
            </div>
        </section>

        <section class="extras">
            {$tributaryAccountBlock}
            {$banksSection}
            {$paymentBrandsSection}
            {$electronicSignatureBlock}
        </section>
    </section>
</body>
</html>
HTML;
        }

    private function renderCommercialDocumentTicketHtml(array $doc, string $format = 'ticket'): string
    {
        $isA4 = $format === 'a4';
                if ($isA4) {
                        return $this->renderCommercialDocumentA4LegacyHtml($doc);
                }

        $company = is_array($doc['company'] ?? null) ? $doc['company'] : [];
        $companyTradeName = trim((string) ($company['trade_name'] ?? $company['tradeName'] ?? ''));
        $companyLegalName = trim((string) ($company['legal_name'] ?? $company['legalName'] ?? ''));
        $companyDescription = trim((string) ($company['company_description'] ?? $company['companyDescription'] ?? ''));

        $title = $this->escapeHtml($companyTradeName !== '' ? $companyTradeName : ($companyLegalName !== '' ? $companyLegalName : 'SISTEMA FACTURACION'));
        $legalNameHtml = ($companyLegalName !== '' && $companyTradeName !== '' && $companyLegalName !== $companyTradeName)
            ? '<div class="brand-legal">' . $this->escapeHtml($companyLegalName) . '</div>'
            : '';
        $taxId = $this->escapeHtml((string) ($company['tax_id'] ?? $company['taxId'] ?? ''));
        $address = $this->escapeHtml((string) ($company['address'] ?? ''));
        $phone = $this->escapeHtml((string) ($company['phone'] ?? ''));
        $email = $this->escapeHtml((string) ($company['email'] ?? ''));
        $companyDescriptionHtml = $companyDescription !== ''
            ? '<div class="company-description">' . $this->escapeHtml($companyDescription) . '</div>'
            : '';
        $docKindRaw = strtoupper(trim((string) ($doc['documentKind'] ?? 'DOCUMENTO')));
        $isSalesOrderDocument = $docKindRaw === 'SALES_ORDER';
        $docKindLabel = [
            'INVOICE' => 'FACTURA ELECTRONICA',
            'RECEIPT' => 'BOLETA DE VENTA ELECTRONICA',
            'CREDIT_NOTE' => 'NOTA DE CREDITO',
            'DEBIT_NOTE' => 'NOTA DE DEBITO',
            'SALES_ORDER' => 'PEDIDO DE VENTA',
            'QUOTATION' => 'COTIZACION',
        ][$docKindRaw] ?? ($docKindRaw !== '' ? $docKindRaw : 'DOCUMENTO');
        $docKind = $this->escapeHtml($docKindLabel);
        $series = $this->escapeHtml((string) ($doc['series'] ?? ''));
        $number = str_pad((string) ((int) ($doc['number'] ?? 0)), 6, '0', STR_PAD_LEFT);
        $issueAt = $this->escapeHtml($this->formatIssueDateTime((string) ($doc['issueDate'] ?? '')));
        $docMetadata = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
        $customer = $this->escapeHtml((string) ($doc['customerName'] ?? '-'));
        $customerDoc = $this->escapeHtml((string) ($doc['customerDocNumber'] ?? '-'));
        $customerAddress = $this->escapeHtml((string) ($doc['customerAddress'] ?? '-'));
        $customerPhone = $this->escapeHtml((string) (
            $doc['customerPhone']
            ?? $docMetadata['customer_phone']
            ?? $docMetadata['customerPhone']
            ?? ''
        ));
        $documentNotes = trim((string) ($doc['notes'] ?? ''));
        $documentNotesRow = $documentNotes !== ''
            ? '<div class="info-row"><div class="info-label">OBSERVACIONES:</div><div class="info-value">' . $this->escapeHtml($documentNotes) . '</div></div>'
            : '';
        $companyId = (int) ($company['company_id'] ?? $company['id'] ?? 0);
        $branchId = isset($doc['branchId']) && $doc['branchId'] !== null
            ? (int) $doc['branchId']
            : null;
        $salesOrderMultiPaymentEnabled = $companyId > 0
            ? $this->isSalesOrderMultiPaymentEnabledForContext($companyId, $branchId)
            : false;
        $workshopMultiVehicleEnabled = $companyId > 0
            ? $this->isWorkshopMultiVehicleEnabledForContext($companyId, $branchId)
            : false;
        $customerPhoneRow = $workshopMultiVehicleEnabled && $customerPhone !== ''
            ? '<div class="info-row"><div class="info-label">TEL.:</div><div class="info-value">' . $customerPhone . '</div></div>'
            : '';
        $vehiclePlate = trim((string) (
            $doc['vehiclePlateSnapshot']
            ?? $docMetadata['vehicle_plate']
            ?? $docMetadata['vehiclePlateSnapshot']
            ?? ''
        ));
        $vehicleBrand = trim((string) (
            $doc['vehicleBrandSnapshot']
            ?? $docMetadata['vehicle_brand']
            ?? $docMetadata['vehicleBrand']
            ?? ''
        ));
        $vehicleModel = trim((string) (
            $doc['vehicleModelSnapshot']
            ?? $docMetadata['vehicle_model']
            ?? $docMetadata['vehicleModel']
            ?? ''
        ));
        $vehicleParts = array_values(array_filter([$vehiclePlate, $vehicleBrand, $vehicleModel], static fn ($v) => trim((string) $v) !== ''));
        $vehicleLabel = implode(' ', $vehicleParts);
        $vehicleRow = $workshopMultiVehicleEnabled && $vehicleLabel !== ''
            ? '<div class="info-row"><div class="info-label">VEHICULO:</div><div class="info-value">' . $this->escapeHtml($vehicleLabel) . '</div></div>'
            : '';
        $paymentMethod = $this->escapeHtml((string) ($doc['paymentMethodName'] ?? '-'));
        $currency = $this->escapeHtml((string) ($doc['currencySymbol'] ?? 'S/'));
        $paymentBreakdownRows = is_array($docMetadata['payment_breakdown'] ?? null)
            ? $docMetadata['payment_breakdown']
            : [];
        $paymentBreakdownHtml = '';
        foreach ($paymentBreakdownRows as $paymentRow) {
            if (!is_array($paymentRow)) {
                continue;
            }

            $amount = round((float) ($paymentRow['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $methodName = trim((string) (
                $paymentRow['payment_method_name']
                ?? $paymentRow['method_name']
                ?? $paymentRow['name']
                ?? ''
            ));
            if ($methodName === '') {
                $methodId = isset($paymentRow['payment_method_id']) ? (int) $paymentRow['payment_method_id'] : 0;
                $methodName = $methodId > 0 ? ('Metodo #' . $methodId) : 'Metodo de pago';
            }

            $status = strtoupper(trim((string) ($paymentRow['status'] ?? 'PAID')));
            $statusSuffix = $status !== '' && $status !== 'PAID' ? ' (' . $status . ')' : '';
            $paymentBreakdownHtml .= '<div class="summary-row"><span class="summary-label">Pago ' . $this->escapeHtml($methodName) . $this->escapeHtml($statusSuffix) . '</span><span class="summary-value">' . $currency . ' ' . $this->formatAmount($amount) . '</span></div>';
        }
        $hideGenericPaymentSummary = $isSalesOrderDocument && $salesOrderMultiPaymentEnabled;
        if (!$hideGenericPaymentSummary) {
            $paymentBreakdownHtml = '';
        }
        $paymentMethodSummaryRow = $hideGenericPaymentSummary
            ? ''
            : '<div class="summary-row"><span class="summary-label">FORMA PAGO</span><span class="summary-value">' . $paymentMethod . '</span></div>';
        $currencyCode = (string) ($doc['currencyCode'] ?? 'PEN');
        $totalInWords = $this->escapeHtml($this->amountToSpanishWords((float) ($doc['grandTotal'] ?? 0), $currencyCode));
        $total = $currency . ' ' . $this->formatAmount((float) ($doc['grandTotal'] ?? 0));
        $gravadaTotal = (float) ($doc['gravadaTotal'] ?? 0);
        $inafectaTotal = (float) ($doc['inafectaTotal'] ?? 0);
        $exoneradaTotal = (float) ($doc['exoneradaTotal'] ?? 0);
        $taxTotal = (float) ($doc['taxTotal'] ?? 0);
        $itemDiscountTotal = 0.0;
        $hasTributarySummary = in_array(strtoupper(trim((string) ($doc['documentKind'] ?? ''))), ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'], true);
        $fileName = $this->escapeHtml(trim((string) ($doc['series'] ?? '')) . '-' . trim((string) ($doc['number'] ?? '')) . '.pdf');
        $taxIdRow = $taxId !== '' ? '<div class="meta">RUC: ' . $taxId . '</div>' : '';
        $addressRow = $address !== '' ? '<div class="meta">' . $address . '</div>' : '';
        $phoneRow = $phone !== '' ? '<div class="meta">TEL: ' . $phone . '</div>' : '';
        $emailRow = $email !== '' ? '<div class="meta">EMAIL: ' . $email . '</div>' : '';

        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $itemRows = '';
        foreach ($items as $item) {
            $description = $this->escapeHtml((string) ($item['description'] ?? '-'));
            $qty = $this->formatAmount((float) ($item['qty'] ?? 0));
            $unitPrice = $this->formatAmount((float) ($item['unitPrice'] ?? 0));
            $lineTotal = $this->formatAmount((float) ($item['lineTotal'] ?? 0));
            $lineNo = (int) ($item['lineNo'] ?? 0);
            $unitLabel = $this->escapeHtml((string) ($item['unitLabel'] ?? 'NIU'));
            $itemDiscountTotal += (float) ($item['discountTotal'] ?? 0);
            $itemMetadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $productCodeRaw = trim((string) (
                $item['productCode']
                ?? $item['product_code']
                ?? $itemMetadata['product_code']
                ?? $itemMetadata['productCode']
                ?? $itemMetadata['code']
                ?? ''
            ));
            if ($productCodeRaw === '' && !empty($item['productId'])) {
                $productCodeRaw = 'ID-' . (int) $item['productId'];
            }
            $itemCodeHtml = $productCodeRaw !== ''
                ? '<div class="item-code">COD: ' . $this->escapeHtml($productCodeRaw) . '</div>'
                : '';

            if ($isA4) {
                $itemRows .= "\n                <tr class=\"items-a4-row\"><td class=\"ta-c\">" . ($lineNo > 0 ? (string) $lineNo : '-') . "</td><td class=\"ta-c\">" . ($productCodeRaw !== '' ? $this->escapeHtml($productCodeRaw) : '-') . "</td><td class=\"ta-r\">{$qty}</td><td class=\"ta-c\">{$unitLabel}</td><td>{$description}</td><td class=\"ta-r\">{$currency} {$unitPrice}</td><td class=\"ta-r\">{$currency} {$lineTotal}</td></tr>\n";
            } else {
                $itemRows .= "\n                <tr class=\"item-desc-row\"><td class=\"item-desc\">{$itemCodeHtml}{$description}</td></tr>\n";
                $itemRows .= "                <tr class=\"item-price-row\"><td><div class=\"item-price-wrap\"><span class=\"item-price-unit\">{$qty} x {$currency} {$unitPrice}</span><span class=\"item-price-total\">{$currency} {$lineTotal}</span></div></td></tr>\n";
            }
        }

        if ($itemRows === '') {
            $itemRows = '<tr><td style="text-align:center;font-weight:800">Sin items</td></tr>';
        }

        $logoUrl = $this->escapeHtml((string) ($company['logo_url'] ?? $company['logoUrl'] ?? ''));
        $logoHtml = $logoUrl !== '' ? '<img src="' . $logoUrl . '" alt="Logo" class="header-logo" />' : '';
        $bankAccounts = is_array($company['bank_accounts'] ?? null)
            ? $company['bank_accounts']
            : (is_array($company['bankAccounts'] ?? null) ? $company['bankAccounts'] : []);
        $bankRows = '';
        foreach ($bankAccounts as $bank) {
            if (!is_array($bank)) {
                continue;
            }
            $bankName = $this->escapeHtml((string) ($bank['bank_name'] ?? ''));
            $account = $this->escapeHtml((string) ($bank['account_number'] ?? ''));
            $cci = $this->escapeHtml((string) ($bank['cci'] ?? ''));
            $holder = $this->escapeHtml((string) ($bank['account_holder'] ?? ''));

            if ($bankName === '' && $account === '' && $cci === '' && $holder === '') {
                continue;
            }

            $bankRows .= '<div class="company-footer-bank">';
            if ($bankName !== '') {
                $bankRows .= '<div><strong>' . $bankName . '</strong></div>';
            }
            if ($account !== '') {
                $bankRows .= '<div>Cuenta: ' . $account . '</div>';
            }
            if ($cci !== '') {
                $bankRows .= '<div>CCI: ' . $cci . '</div>';
            }
            if ($holder !== '') {
                $bankRows .= '<div>Titular: ' . $holder . '</div>';
            }
            $bankRows .= '</div>';
        }

        $banksSection = $bankRows !== ''
            ? '<div class="company-footer-banks"><div class="company-footer-title">Bancos</div>' . $bankRows . '</div>'
            : '';

        $showPaymentBrandsRaw = $company['show_payment_brand_icons'] ?? $company['showPaymentBrandIcons'] ?? true;
        if ($showPaymentBrandsRaw === null) {
            $showPaymentBrands = true;
        } else {
            $showPaymentBrands = filter_var($showPaymentBrandsRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($showPaymentBrands === null) {
                $showPaymentBrands = (bool) $showPaymentBrandsRaw;
            }
        }

        $paymentBrandsSection = '';
        if ($showPaymentBrands) {
            $logosClass = $isA4 ? 'company-footer-logos company-footer-logos--a4' : 'company-footer-logos company-footer-logos--ticket';
            $yapeLogo = $this->escapeHtml($this->resolvePaymentBrandImageSource('yape-official.png'));
            $plinLogo = $this->escapeHtml($this->resolvePaymentBrandImageSource('plin-official.png'));
            $culqiLogo = $this->escapeHtml($this->resolvePaymentBrandImageSource('culqi-official.png'));

            $paymentBrandsSection = '<div class="' . $logosClass . '">'
                . '<div class="paybrand"><img src="' . $yapeLogo . '" alt="Yape" /></div>'
                . '<div class="paybrand"><img src="' . $plinLogo . '" alt="Plin" /></div>'
                . '<div class="paybrand"><img src="' . $culqiLogo . '" alt="Culqi" /></div>'
                . '</div>';
        }

        $sheetWidth = $isA4 ? '100%' : '80mm';
        $sheetMaxWidth = $isA4 ? '194mm' : '80mm';
        $pageSize = $isA4 ? 'A4 portrait' : '80mm auto';
        $pageMargin = $isA4 ? '8mm' : '0';
        $logoMaxWidth = $isA4 ? '140px' : '74mm';
        $logoMaxHeight = $isA4 ? '90px' : '40mm';
        $headerClass = $isA4 ? 'header header--a4' : 'header';
        $headerCopyClass = $isA4 ? 'header-copy header-copy--a4' : 'header-copy';
        $bodyFontSize = $isA4 ? '10pt' : '13px';
        $sheetPadding = $isA4 ? '6mm' : '3mm';
        $titleFontSize = $isA4 ? '13pt' : '15px';
        $docNoFontSize = $isA4 ? '14pt' : '16px';
        $metaFontSize = $isA4 ? '9pt' : '12px';
        $infoFontSize = $isA4 ? '9pt' : '12px';
        $itemCodeFontSize = $isA4 ? '8pt' : '10px';
        $itemDescFontSize = $isA4 ? '9pt' : '12px';
        $itemUnitFontSize = $isA4 ? '9pt' : '12px';
        $itemTotalFontSize = $isA4 ? '9pt' : '13px';
        $summaryFontSize = $isA4 ? '9pt' : '12px';
        $totalFontSize = $isA4 ? '12pt' : '15px';
        $itemDescPaddingTop = $isA4 ? '1mm' : '1.1mm';
        $itemDescPaddingBottom = $isA4 ? '0.5mm' : '0.4mm';
        $itemPricePaddingTop = $isA4 ? '0' : '0.1mm';
        $itemPricePaddingBottom = $isA4 ? '1mm' : '1.1mm';
        $a4ItemTableHead = $isA4
            ? '<thead><tr><th style="width:7mm">#</th><th style="width:22mm">CODIGO</th><th style="width:16mm">CANT.</th><th style="width:14mm">UNID.</th><th>DESCRIPCION</th><th style="width:22mm">VALOR U.</th><th style="width:24mm">VALOR TOTAL</th></tr></thead>'
            : '';
        $itemsTableClass = $isA4 ? 'items-a4' : '';
        $a4SummaryRows = $isA4
            ? '<div class="summary-row"><span class="summary-label">Op. Gravadas</span><span class="summary-value">' . $currency . ' ' . $this->formatAmount($gravadaTotal) . '</span></div>'
                . '<div class="summary-row"><span class="summary-label">Op. Inafectas</span><span class="summary-value">' . $currency . ' ' . $this->formatAmount($inafectaTotal) . '</span></div>'
                . '<div class="summary-row"><span class="summary-label">Op. Exoneradas</span><span class="summary-value">' . $currency . ' ' . $this->formatAmount($exoneradaTotal) . '</span></div>'
                . '<div class="summary-row"><span class="summary-label">IGV</span><span class="summary-value">' . $currency . ' ' . $this->formatAmount($taxTotal) . '</span></div>'
                . (($itemDiscountTotal > 0.00001)
                    ? '<div class="summary-row"><span class="summary-label">Dscto. item</span><span class="summary-value">-' . $currency . ' ' . $this->formatAmount($itemDiscountTotal) . '</span></div>'
                    : '')
            : '';

        $resolveTaxAccountNumber = function (array $metadata, string $flatKey, string $nestedKey): string {
            $flat = trim((string) ($metadata[$flatKey] ?? ''));
            if ($flat !== '') {
                return $flat;
            }

            $nested = $metadata[$nestedKey] ?? null;
            if (!is_array($nested)) {
                return '';
            }

            return trim((string) ($nested['account_number'] ?? ''));
        };

        $taxConditionRows = '';
        if (!empty($docMetadata['has_detraccion'])) {
            $account = $resolveTaxAccountNumber($docMetadata, 'detraccion_account_number', 'detraccion_account');
            if ($account !== '') {
                $taxConditionRows .= '<div class="summary-row"><span class="summary-label">Cuenta detraccion</span><span class="summary-value">' . $this->escapeHtml($account) . '</span></div>';
            }
        }
        if (!empty($docMetadata['has_retencion'])) {
            $account = $resolveTaxAccountNumber($docMetadata, 'retencion_account_number', 'retencion_account');
            if ($account !== '') {
                $taxConditionRows .= '<div class="summary-row"><span class="summary-label">Cuenta retencion</span><span class="summary-value">' . $this->escapeHtml($account) . '</span></div>';
            }
        }
        if (!empty($docMetadata['has_percepcion'])) {
            $account = $resolveTaxAccountNumber($docMetadata, 'percepcion_account_number', 'percepcion_account');
            if ($account !== '') {
                $taxConditionRows .= '<div class="summary-row"><span class="summary-label">Cuenta percepcion</span><span class="summary-value">' . $this->escapeHtml($account) . '</span></div>';
            }
        }

        $ticketTaxAccountsBlock = $taxConditionRows !== ''
            ? '<div class="company-footer-title">Condiciones tributarias</div>' . $taxConditionRows
            : '';

        $electronicSignatureRaw = $this->findFirstMetaStringValue($docMetadata, [
            'sunat_electronic_signature',
            'sunat_signature',
            'firma_electronica',
            'firma',
            'signature',
            'hash_cpe',
            'codigo_hash',
            'digest_value',
            'digestValue',
        ]);
        $electronicSignatureRaw = $this->normalizeElectronicSignatureValue($electronicSignatureRaw);
        $ticketElectronicSignatureBlock = $electronicSignatureRaw !== ''
            ? '<div class="summary-words">Firma electronica: ' . $this->escapeHtml($electronicSignatureRaw) . '</div>'
            : '';

        $a4HeaderHtml = $isA4 ? <<<A4HEAD
<div class="header--a4">
    <div class="logo-col">
        {$logoHtml}
    </div>
    <div class="brand-col">
        <div class="brand-name">{$title}</div>
        {$legalNameHtml}
        {$companyDescriptionHtml}
        {$addressRow}
        {$phoneRow}
        {$emailRow}
    </div>
    <div class="voucher-box">
        <div class="voucher-ruc">R.U.C. {$taxId}</div>
    <div class="voucher-type">{$docKind}</div>
    <div class="voucher-number">{$series}-{$number}</div>
    <div class="voucher-date">{$issueAt}</div>
  </div>
</div>
A4HEAD
            : <<<TICKETHEAD
<div class="{$headerClass}">
  {$logoHtml}
  <div class="{$headerCopyClass}">
    <div class="title">{$title}</div>
    {$companyDescriptionHtml}
    {$taxIdRow}
    {$addressRow}
    {$phoneRow}
    {$emailRow}
    <div class="title">{$docKind}</div>
    <div class="docno">{$series}-{$number}</div>
    <div class="meta">{$issueAt}</div>
  </div>
</div>
TICKETHEAD;

        return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>{$fileName}</title>
  <style>
    @media print { @page { size: {$pageSize}; margin: {$pageMargin}; } .no-print { display: none !important; } body { margin: 0; padding: 0; } }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: 'Arial', 'Helvetica', sans-serif; background: #fff; color: #000; font-size: {$bodyFontSize}; line-height: 1.3; font-weight: 700; }
    .sheet { width: {$sheetWidth}; max-width: {$sheetMaxWidth}; margin: 0 auto; padding: {$sheetPadding}; }
    /* A4 header: 2 cols, left=brand, right=fiscal box */
    /* A4 header: 3 cols (logo | company info | fiscal box) */
    .header--a4 { display: grid; grid-template-columns: auto 1fr 58mm; gap: 4mm; align-items: stretch; margin-bottom: 4mm; padding-bottom: 3mm; border-bottom: 2px solid #1e3a8a; }
    .logo-col { display: flex; align-items: center; justify-content: center; padding-right: 2mm; border-right: 1px solid #e2e8f0; }
    .brand-col { display: flex; flex-direction: column; justify-content: center; gap: 0.4mm; }
    .header-logo { display: block; max-width: {$logoMaxWidth}; max-height: {$logoMaxHeight}; height: auto; object-fit: contain; margin: 0 auto; }
    .brand-name { font-size: {$titleFontSize}; font-weight: 900; text-transform: uppercase; color: #1e3a8a; margin-bottom: 0.3mm; }
    .brand-legal { font-size: 8pt; font-weight: 700; color: #374151; text-transform: uppercase; margin-bottom: 0.8mm; }
    .company-description { font-size: 8.5pt; font-weight: 700; color: #374151; }
    /* Fiscal box (right) */
    .voucher-box { border: 2px solid #1e3a8a; border-radius: 4px; overflow: hidden; text-align: center; }
    .voucher-ruc { padding: 2.5mm 3mm; font-size: 9.5pt; font-weight: 900; color: #1e3a8a; background: #fff; }
    .voucher-type { padding: 3mm; background: #1e3a8a; color: #fff; font-size: 9pt; font-weight: 900; text-transform: uppercase; line-height: 1.3; }
    .voucher-number { padding: 3mm; font-size: 15pt; font-weight: 900; color: #dc2626; letter-spacing: 0.5px; background: #fff; }
    .voucher-date { font-size: 8pt; color: #374151; padding: 1.5mm 3mm; background: #f8fafc; border-top: 1px solid #bfdbfe; }
    /* Ticket header */
    .header { text-align: center; margin-bottom: 2mm; }
    .header-copy--a4 { text-align: left; }
    .title { font-size: {$titleFontSize}; font-weight: 900; text-transform: uppercase; margin-bottom: 0.6mm; }
    .docno { font-size: {$docNoFontSize}; font-weight: 900; letter-spacing: 0.4px; margin-bottom: 0.6mm; }
    .meta { font-size: {$metaFontSize}; font-weight: 800; margin: 0.2mm 0; }
    .divider { border-top: 1px dashed #000; margin: 2mm 0; }
    .info-row { display: flex; justify-content: space-between; gap: 2mm; font-size: {$infoFontSize}; margin: 0.5mm 0; }
    .info-label { font-weight: 900; flex-shrink: 0; }
    .info-value { font-weight: 800; text-align: right; flex: 1; }
    table { width: 100%; border-collapse: collapse; }
    .items-a4 { border: 1px solid #cbd5e1; overflow: hidden; }
    .items-a4 thead th { background: #1e3a8a; color: #fff; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.2px; padding: 1.5mm 2mm; border-bottom: 1px solid #1e3a8a; font-weight: 700; }
    .items-a4 tbody td { border-bottom: 1px solid #e2e8f0; font-size: 8.5pt; padding: 1.5mm 2mm; vertical-align: top; }
    .items-a4-row:last-child td { border-bottom: none; }
    .ta-r { text-align: right; }
    .ta-c { text-align: center; }
    td { padding: 0; }
    .item-desc-row td { padding-top: {$itemDescPaddingTop}; padding-bottom: {$itemDescPaddingBottom}; }
    .item-desc { font-size: {$itemDescFontSize}; line-height: 1.2; font-weight: 900; }
    .item-code { font-size: {$itemCodeFontSize}; font-weight: 800; margin-bottom: 0.2mm; }
    .item-price-row td { padding-top: {$itemPricePaddingTop}; padding-bottom: {$itemPricePaddingBottom}; }
    .item-price-wrap { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; }
    .item-price-unit { font-size: {$itemUnitFontSize}; font-weight: 900; }
    .item-price-total { font-size: {$itemTotalFontSize}; font-weight: 900; white-space: nowrap; }
    .summary { border-top: 2px solid #1e3a8a; margin-top: 2mm; padding-top: 1.5mm; }
    .summary-row { display: flex; justify-content: space-between; font-size: {$summaryFontSize}; margin: 0.6mm 0; }
    .summary-label, .summary-value { font-weight: 900; }
    .total-row { display: flex; justify-content: space-between; border-top: 2px solid #1e3a8a; margin-top: 1mm; padding-top: 1mm; font-size: {$totalFontSize}; font-weight: 900; background: #f0f4ff; padding-left: 2mm; padding-right: 2mm; border-radius: 4px; }
    .summary-words { margin-top: 0.8mm; font-size: {$summaryFontSize}; font-weight: 900; line-height: 1.25; word-break: break-word; }
    .footer { margin-top: 2mm; border-top: 1px dashed #000; padding-top: 1.5mm; font-size: 9pt; font-weight: 700; }
    .company-footer-title { text-transform: uppercase; margin-bottom: 0.8mm; font-size: 9pt; font-weight: 900; }
    .company-footer-bank { margin: 0.5mm 0; font-size: 9pt; font-weight: 700; }
    .company-footer-logos { display: flex; align-items: center; justify-content: center; gap: 1.4mm; margin-top: 1mm; flex-wrap: wrap; }
    .company-footer-logos--a4 { justify-content: flex-start; margin-top: 1.4mm; }
    .paybrand { border: 1px solid #d1d5db; border-radius: 8px; background: #fff; padding: 1mm 2mm; height: 10mm; display: inline-flex; align-items: center; justify-content: center; }
    .paybrand img { height: 7mm; width: auto; display: block; }
    .company-footer-logos--ticket .paybrand { height: 9mm; padding: 0.8mm 1.5mm; }
    .company-footer-logos--ticket .paybrand img { height: 6mm; }
  </style>
</head>
<body>
  <div class="sheet">
    {$a4HeaderHtml}

    <div class="divider"></div>

    <div class="info-row"><div class="info-label">CLIENTE:</div><div class="info-value">{$customer}</div></div>
    <div class="info-row"><div class="info-label">DOC.:</div><div class="info-value">{$customerDoc}</div></div>
    <div class="info-row"><div class="info-label">DIRECCI&Oacute;N:</div><div class="info-value">{$customerAddress}</div></div>
    {$customerPhoneRow}
    {$documentNotesRow}
    {$vehicleRow}

    <div class="divider"></div>

    <table class="{$itemsTableClass}">
{$a4ItemTableHead}
<tbody>
{$itemRows}    </tbody></table>

    <div class="summary">
            {$a4SummaryRows}
      <div class="total-row"><span>TOTAL</span><span>{$total}</span></div>
            <div class="summary-words">SON: {$totalInWords}</div>
        {$paymentMethodSummaryRow}
        {$paymentBreakdownHtml}
    </div>

    <div class="footer">
            {$ticketTaxAccountsBlock}
            {$ticketElectronicSignatureBlock}
      {$banksSection}
            {$paymentBrandsSection}
      <div>Gracias por su compra</div>
    </div>
  </div>
</body>
</html>
HTML;
    }

    private function findFirstMetaStringValue(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $candidate = $source[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($source as $value) {
            if (is_array($value)) {
                $nested = $this->findFirstMetaStringValue($value, $keys);
                if ($nested !== '') {
                    return $nested;
                }
                continue;
            }

            if (is_object($value)) {
                $nested = $this->findFirstMetaStringValue((array) $value, $keys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function resolveFrontendAssetUrl(string $assetPath): string
    {
        $normalizedPath = '/' . ltrim($assetPath, '/');

        $candidates = [
            trim((string) env('FRONTEND_URL', '')),
            trim((string) config('app.frontend_url', '')),
            trim((string) request()->headers->get('origin', '')),
        ];

        foreach ($candidates as $baseUrl) {
            if ($baseUrl === '') {
                continue;
            }

            if (preg_match('#^https?://#i', $baseUrl) !== 1) {
                continue;
            }

            return rtrim($baseUrl, '/') . $normalizedPath;
        }

        return $normalizedPath;
    }

    private function resolvePaymentBrandImageSource(string $fileName): string
    {
        $safeName = basename(trim($fileName));
        if ($safeName === '') {
            return '';
        }

        $relativePath = '/assets/payment-logos/' . $safeName;
        $candidates = [
            public_path('assets/payment-logos/' . $safeName),
            dirname(base_path()) . DIRECTORY_SEPARATOR . 'facturacion_frontend' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'payment-logos' . DIRECTORY_SEPARATOR . $safeName,
        ];

        foreach ($candidates as $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }

            $dataUri = $this->filePathToImageDataUri($path);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        return $this->resolveFrontendAssetUrl($relativePath);
    }

    private function filePathToImageDataUri(string $path): ?string
    {
        try {
            if (!is_file($path) || !is_readable($path)) {
                return null;
            }

            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                return null;
            }

            $mime = $this->guessImageMimeType($path, $contents);
            if ($mime === null) {
                return null;
            }

            return 'data:' . $mime . ';base64,' . base64_encode($contents);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function guessImageMimeType(string $path, string $contents): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_buffer($finfo, $contents);
                @finfo_close($finfo);
                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    return $detected;
                }
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    private function normalizeElectronicSignatureValue(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '{') && str_ends_with($value, '}'))
            || (str_starts_with($value, '[') && str_ends_with($value, ']'))
        ) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $firstScalar = $this->extractFirstScalarValue($decoded);
                if ($firstScalar !== '') {
                    return $firstScalar;
                }
            }
        }

        return $value;
    }

    private function extractFirstScalarValue($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                $scalar = $this->extractFirstScalarValue($nested);
                if ($scalar !== '') {
                    return $scalar;
                }
            }
        }

        return '';
    }

    private function formatIssueDateTime(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '-';
        }

        try {
            return Carbon::parse($value)->setTimezone('America/Lima')->format('d/m/Y H:i:s');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function isSellerUserRole(string $roleCode, string $roleProfile): bool
    {
        if ($roleProfile === 'SELLER' || str_contains($roleProfile, 'VENDED')) {
            return true;
        }

        foreach (self::SELLER_ROLE_MARKERS as $marker) {
            if (str_contains($roleCode, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isCashierUserRole(string $roleCode, string $roleProfile): bool
    {
        if ($roleProfile === 'CASHIER') {
            return true;
        }

        foreach (self::CASHIER_ROLE_MARKERS as $marker) {
            if (str_contains($roleCode, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isAdminUserRole(string $roleCode, string $roleProfile): bool
    {
        if ($this->isSellerUserRole($roleCode, $roleProfile)) {
            return false;
        }

        return str_contains($roleCode, 'ADMIN');
    }

    private function amountToSpanishWords(float $amount, string $currencyCode = 'PEN'): string
    {
        $safeAmount = max(0, $amount);
        $integerPart = (int) floor($safeAmount);
        $decimalPart = (int) round(($safeAmount - $integerPart) * 100);

        if ($decimalPart >= 100) {
            $integerPart += 1;
            $decimalPart = 0;
        }

        $currencyName = strtoupper(trim($currencyCode)) === 'USD' ? 'DOLARES' : 'SOLES';
        $decimalText = str_pad((string) $decimalPart, 2, '0', STR_PAD_LEFT);
        $words = strtoupper($this->numberToSpanishWords($integerPart));

        return $words . ' CON ' . $decimalText . '/100 ' . $currencyName;
    }

    private function numberToSpanishWords(int $number): string
    {
        if ($number === 0) {
            return 'cero';
        }

        $millions = intdiv($number, 1000000);
        $thousands = intdiv($number % 1000000, 1000);
        $hundreds = $number % 1000;
        $parts = [];

        if ($millions > 0) {
            if ($millions === 1) {
                $parts[] = 'un millon';
            } else {
                $parts[] = $this->numberToSpanishWords($millions) . ' millones';
            }
        }

        if ($thousands > 0) {
            if ($thousands === 1) {
                $parts[] = 'mil';
            } else {
                $parts[] = $this->convertThreeDigitsToSpanishWords($thousands) . ' mil';
            }
        }

        if ($hundreds > 0) {
            $parts[] = $this->convertThreeDigitsToSpanishWords($hundreds);
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    private function convertThreeDigitsToSpanishWords(int $number): string
    {
        $units = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        $teens = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve'];
        $tens = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $hundreds = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if ($number === 0) {
            return '';
        }

        if ($number === 100) {
            return 'cien';
        }

        $c = intdiv($number, 100);
        $rest = $number % 100;
        $parts = [];

        if ($c > 0) {
            $parts[] = $hundreds[$c];
        }

        if ($rest >= 10 && $rest <= 19) {
            $parts[] = $teens[$rest - 10];
        } else {
            $d = intdiv($rest, 10);
            $u = $rest % 10;

            if ($d === 2 && $u > 0) {
                $parts[] = 'veinti' . $units[$u];
            } else {
                if ($d > 0) {
                    $parts[] = $tens[$d];
                }

                if ($u > 0) {
                    if ($d > 2) {
                        $parts[] = 'y ' . $units[$u];
                    } elseif ($d === 0) {
                        $parts[] = $units[$u];
                    }
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    private function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function registerCashIncomeFromDocument(
        int $companyId,
        ?int $branchId,
        ?int $cashRegisterId,
        int $documentId,
        string $documentKind,
        string $series,
        int $number,
        float $paidTotal,
        int $userId,
        array $payments = []
    ): void {
        if ($cashRegisterId === null || $paidTotal <= 0) {
            return;
        }

        $firstPaidMethod = collect($payments)
            ->first(function ($payment) {
                return ($payment['status'] ?? 'PENDING') === 'PAID';
            });

        $this->salesLookupService->registerCashIncomeFromDocument(
            $companyId,
            $branchId,
            $cashRegisterId,
            $documentId,
            $documentKind,
            $series,
            $number,
            $paidTotal,
            $userId,
            isset($firstPaidMethod['payment_method_id']) ? (int) $firstPaidMethod['payment_method_id'] : null
        );
    }

    private function resolveTaxCategories(int $companyId)
    {
        $rows = collect($this->salesLookupService->resolveTaxCategoriesRows($companyId));

        if ($rows->isEmpty()) {
            return collect();
        }

        return collect($this->companyIgvRateService->applyActiveRateToTaxCategories($companyId, $rows->all()));
    }

    private function resolveDocumentNoteReasons(string $documentKind): array
    {
        $normalizedKind = $this->salesBusinessRuleService->resolveNoteBaseKind($documentKind) ?? strtoupper($documentKind);
        return $this->salesLookupService->resolveDocumentNoteReasonsRows($normalizedKind);
    }

    private function getDetractionMinAmount(int $companyId, $branchId): float
    {
        // Read min_amount from toggle config JSON; fallback to SUNAT default 700 PEN
        $resolvedBranchId = $branchId !== null ? (int) $branchId : null;
        $row = $this->resolveFeatureToggleRow($companyId, $resolvedBranchId, 'SALES_DETRACCION_ENABLED');
        if ($row && !empty($row->config)) {
            $config = is_string($row->config) ? json_decode($row->config, true) : (array) $row->config;
            if (isset($config['min_amount']) && is_numeric($config['min_amount'])) {
                return (float) $config['min_amount'];
            }
        }
        return 700.00;
    }

    private function resolveRetencionTypes(int $companyId, $branchId): array
    {
        $defaultRate = 3.00;
        $defaultType = [
            'code' => 'RET_IGV_3',
            'name' => 'Retencion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_RETENCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $configuredTypes = isset($config['retencion_types']) && is_array($config['retencion_types'])
            ? $config['retencion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePercepcionTypes(int $companyId, $branchId): array
    {
        $defaultRate = 2.00;
        $defaultType = [
            'code' => 'PERC_IGV_2',
            'name' => 'Percepcion IGV',
            'rate_percent' => $defaultRate,
        ];

        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_PERCEPCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $configuredTypes = isset($config['percepcion_types']) && is_array($config['percepcion_types'])
            ? $config['percepcion_types']
            : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $rate = isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                    ? (float) $item['rate_percent']
                    : $defaultRate;

                return [
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rate,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolveSunatOperationTypes(int $companyId, $branchId): array
    {
        $defaultRows = [
            ['code' => '0101', 'name' => 'Venta interna', 'regime' => 'NONE'],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION'],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION'],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION'],
        ];

        $detraccionRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_DETRACCION_ENABLED');
        $retencionRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_RETENCION_ENABLED');
        $percepcionRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_PERCEPCION_ENABLED');
        $detraccionConfig = $this->decodeFeatureConfig($detraccionRow ? $detraccionRow->config : null);
        $retencionConfig = $this->decodeFeatureConfig($retencionRow ? $retencionRow->config : null);
        $percepcionConfig = $this->decodeFeatureConfig($percepcionRow ? $percepcionRow->config : null);

        $configuredRows = [];
        if (isset($detraccionConfig['sunat_operation_types']) && is_array($detraccionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $detraccionConfig['sunat_operation_types']);
        }
        if (isset($retencionConfig['sunat_operation_types']) && is_array($retencionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $retencionConfig['sunat_operation_types']);
        }
        if (isset($percepcionConfig['sunat_operation_types']) && is_array($percepcionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $percepcionConfig['sunat_operation_types']);
        }

        $rows = collect($configuredRows)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                $code = strtoupper(trim((string) ($item['code'] ?? '')));
                $name = trim((string) ($item['name'] ?? ''));
                $regime = strtoupper(trim((string) ($item['regime'] ?? 'NONE')));
                if (!in_array($regime, ['NONE', 'DETRACCION', 'RETENCION', 'PERCEPCION'], true)) {
                    $regime = 'NONE';
                }

                return [
                    'code' => $code,
                    'name' => $name,
                    'regime' => $regime,
                ];
            })
            ->filter(function ($row) {
                return is_array($row) && $row['code'] !== '' && $row['name'] !== '';
            })
            ->unique('code')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : $defaultRows;
    }

    private function resolveFeatureAccountInfo(int $companyId, $branchId, string $featureCode, string $fallbackKeyword): ?array
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);

        $accountNumber = trim((string) ($config['account_number'] ?? ''));
        if ($accountNumber !== '') {
            return [
                'bank_name' => trim((string) ($config['bank_name'] ?? '')),
                'account_number' => $accountNumber,
                'account_holder' => trim((string) ($config['account_holder'] ?? '')),
            ];
        }

        $bankAccounts = $this->resolveCompanyBankAccounts($companyId);
        $keyword = strtoupper(trim($fallbackKeyword));
        foreach ($bankAccounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountType = strtoupper(trim((string) ($account['account_type'] ?? '')));
            $number = trim((string) ($account['account_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            if ($keyword !== '' && strpos($accountType, $keyword) === false) {
                continue;
            }

            return [
                'bank_name' => trim((string) ($account['bank_name'] ?? '')),
                'account_number' => $number,
                'account_holder' => trim((string) ($account['account_holder'] ?? '')),
            ];
        }

        return null;
    }

    private function resolveCompanyBankAccounts(int $companyId): array
    {
        $rawAccounts = $this->salesLookupService->resolveCompanyBankAccountsRaw($companyId);
        if ($rawAccounts === null) {
            return [];
        }

        $decoded = is_string($rawAccounts)
            ? json_decode($rawAccounts, true)
            : (array) $rawAccounts;

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function ($item) {
            return is_array($item);
        }));
    }

    private function resolveFeatureToggleRow(int $companyId, $branchId, string $featureCode)
    {
        $resolvedBranchId = $branchId !== null ? (int) $branchId : null;
        $resolved = $this->resolveFeatureResolutionForContext($companyId, $resolvedBranchId, $featureCode, false);

        if (!$resolved['is_enabled'] && $resolved['config'] === null) {
            return null;
        }

        return (object) [
            'feature_code' => $featureCode,
            'is_enabled' => (bool) $resolved['is_enabled'],
            'config' => $resolved['config'],
            'vertical_source' => $resolved['vertical_source'],
        ];
    }

    private function decodeFeatureConfig($rawConfig): array
    {
        if ($rawConfig === null) {
            return [];
        }

        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        return [];
    }

    private function resolveFeatureResolutionForContext(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled = false): array
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $cacheKey = $companyId . ':' . ($branchId ?? 'null') . ':' . $normalizedFeatureCode . ':' . ($defaultEnabled ? '1' : '0');

        if (array_key_exists($cacheKey, $this->featureContextResolutionCache)) {
            return $this->featureContextResolutionCache[$cacheKey];
        }

        // Pre-warm toggle maps in 2 bulk queries (company + branch) instead of 1 query per feature
        $this->prewarmFeatureToggles($companyId, $branchId);

        $ck = (string) $companyId;
        $companyRow = $this->companyFeatureToggleMap[$ck . ':' . $normalizedFeatureCode] ?? null;

        $branchRow = null;
        if ($branchId !== null) {
            $bk = $ck . ':' . $branchId;
            $branchRow = $this->branchFeatureToggleMap[$bk . ':' . $normalizedFeatureCode] ?? null;
        }

        $branchEnabled = $branchRow && $branchRow->is_enabled !== null ? (bool) $branchRow->is_enabled : null;
        $companyEnabled = $companyRow && $companyRow->is_enabled !== null ? (bool) $companyRow->is_enabled : null;

        $isEnabled = $branchEnabled !== null
            ? $branchEnabled
            : ($companyEnabled !== null ? $companyEnabled : $defaultEnabled);

        $companyConfig = $companyRow ? $this->decodeFeatureConfig($companyRow->config) : [];
        $branchConfig = $branchRow ? $this->decodeFeatureConfig($branchRow->config) : [];
        $resolvedConfig = array_merge($companyConfig, $branchConfig);

        $verticalPreference = $this->resolveVerticalFeaturePreference($companyId, $normalizedFeatureCode);
        if ($verticalPreference['resolved']) {
            // Explicit branch/company values must win; vertical acts as fallback.
            $hasExplicitToggle = $branchEnabled !== null || $companyEnabled !== null;
            $hasExplicitConfig = !empty($resolvedConfig);

            if (!$hasExplicitToggle && $verticalPreference['is_enabled'] !== null) {
                $isEnabled = (bool) $verticalPreference['is_enabled'];
            }
            if (!$hasExplicitConfig && $verticalPreference['config'] !== null) {
                $resolvedConfig = $this->decodeFeatureConfig($verticalPreference['config']);
            }
        }

        $result = [
            'is_enabled' => (bool) $isEnabled,
            'config' => !empty($resolvedConfig) ? $resolvedConfig : null,
            'company_enabled' => $companyEnabled,
            'branch_enabled' => $branchEnabled,
            'vertical_source' => $verticalPreference['source'],
        ];

        $this->featureContextResolutionCache[$cacheKey] = $result;
        return $result;
    }

    private function resolveVerticalFeaturePreference(int $companyId, string $featureCode): array
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $cacheKey = $companyId . ':' . $normalizedFeatureCode;
        if (array_key_exists($cacheKey, $this->verticalFeaturePreferenceCache)) {
            return $this->verticalFeaturePreferenceCache[$cacheKey];
        }

        $default = [
            'resolved' => false,
            'is_enabled' => null,
            'config' => null,
            'source' => null,
        ];

        // Superadmin-only feature codes must never be overridden by vertical templates.
        // Their value is exclusively managed from the Admin Portal.
        $superadminOnly = array_map('strtoupper', config('features.superadmin_only_feature_codes', []));
        if (in_array($normalizedFeatureCode, $superadminOnly, true)) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        if (!$this->tableExists('appcfg.verticals')
            || !$this->tableExists('appcfg.company_verticals')
            || !$this->tableExists('appcfg.vertical_feature_templates')
            || !$this->tableExists('appcfg.company_vertical_feature_overrides')) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $activeVertical = $this->resolveActiveCompanyVertical($companyId);
        if ($activeVertical === null) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $verticalId = (int) ($activeVertical['id'] ?? 0);
        if ($verticalId <= 0) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $this->prewarmVerticalFeaturePreferences($companyId, $verticalId);

        $overrideKey = $companyId . ':' . $verticalId . ':' . $normalizedFeatureCode;
        $override = $this->verticalOverrideMap[$overrideKey] ?? null;

        if ($override && ($override->is_enabled !== null || $override->config !== null)) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => $override->is_enabled !== null ? (bool) $override->is_enabled : null,
                'config' => $override->config,
                'source' => 'COMPANY_VERTICAL_OVERRIDE',
            ];
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $templateKey = $verticalId . ':' . $normalizedFeatureCode;
        $template = $this->verticalTemplateMap[$templateKey] ?? null;

        if ($template && ($template->is_enabled !== null || $template->config !== null)) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => $template->is_enabled !== null ? (bool) $template->is_enabled : null,
                'config' => $template->config,
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

        $resolved = $this->salesLookupService->resolveActiveCompanyVertical($companyId);
        $this->activeVerticalCache[$companyId] = $resolved;
        return $resolved;
    }

    private function resolveDetractionServiceCodes(): array
    {
        return $this->salesLookupService->resolveDetractionServiceCodes();
    }

    private function tableExists(string $qualifiedTable): bool
    {
        if (array_key_exists($qualifiedTable, $this->tableExistsCache)) {
            return $this->tableExistsCache[$qualifiedTable];
        }

        $result = $this->salesLookupService->tableExists($qualifiedTable);
        $this->tableExistsCache[$qualifiedTable] = $result;
        return $result;
    }

    private function prewarmFeatureToggles(int $companyId, ?int $branchId): void
    {
        $ck = (string) $companyId;

        if (!isset($this->featureTogglePrewarmed[$ck])) {
            $rows = $this->salesLookupService->loadCompanyFeatureToggles($companyId);

            foreach ($rows as $row) {
                $fk = $ck . ':' . strtoupper(trim($row->feature_code));
                $this->companyFeatureToggleMap[$fk] = $row;
            }
            $this->featureTogglePrewarmed[$ck] = true;
        }

        if ($branchId !== null) {
            $bk = $ck . ':' . $branchId;
            if (!isset($this->featureTogglePrewarmed[$bk])) {
                $rows = $this->salesLookupService->loadBranchFeatureToggles($companyId, $branchId);

                foreach ($rows as $row) {
                    $fk = $bk . ':' . strtoupper(trim($row->feature_code));
                    $this->branchFeatureToggleMap[$fk] = $row;
                }
                $this->featureTogglePrewarmed[$bk] = true;
            }
        }
    }

    private function prewarmVerticalFeaturePreferences(int $companyId, int $verticalId): void
    {
        $companyVerticalKey = $companyId . ':' . $verticalId;
        if (!isset($this->verticalFeatureMapPrewarmed[$companyVerticalKey])) {
            $rows = $this->salesLookupService->loadVerticalFeatureOverrides($companyId, $verticalId);

            foreach ($rows as $row) {
                $normalizedFeatureCode = strtoupper(trim((string) ($row->feature_code ?? '')));
                if ($normalizedFeatureCode === '') {
                    continue;
                }

                $mapKey = $companyVerticalKey . ':' . $normalizedFeatureCode;
                $this->verticalOverrideMap[$mapKey] = (object) [
                    'is_enabled' => $row->is_enabled,
                    'config' => $row->config,
                ];
            }

            $this->verticalFeatureMapPrewarmed[$companyVerticalKey] = true;
        }

        $verticalKey = (string) $verticalId;
        if (!isset($this->verticalFeatureMapPrewarmed[$verticalKey])) {
            $rows = $this->salesLookupService->loadVerticalFeatureTemplates($verticalId);

            foreach ($rows as $row) {
                $normalizedFeatureCode = strtoupper(trim((string) ($row->feature_code ?? '')));
                if ($normalizedFeatureCode === '') {
                    continue;
                }

                $mapKey = $verticalId . ':' . $normalizedFeatureCode;
                $this->verticalTemplateMap[$mapKey] = (object) [
                    'is_enabled' => $row->is_enabled,
                    'config' => $row->config,
                ];
            }

            $this->verticalFeatureMapPrewarmed[$verticalKey] = true;
        }
    }

    private function tableColumns(string $qualifiedTable): array
    {
        return $this->salesLookupService->tableColumns($qualifiedTable);
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

    private function enabledUnits(int $companyId)
    {
        return $this->salesLookupService->enabledUnits($companyId);
    }

    private function ensureCustomersPhoneColumn(): void
    {
        $this->salesLookupService->ensureCustomersPhoneColumn();
    }

    private function splitQualifiedTable(string $qualifiedTable): array
    {
        if (strpos($qualifiedTable, '.') === false) {
            return ['public', $qualifiedTable];
        }

        [$schema, $table] = explode('.', $qualifiedTable, 2);

        return [$schema, $table];
    }

    private function decodeDocumentMetadata($rawMetadata): array
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

    private function resolveIssueAtForStorage($issueAt)
    {
        if ($issueAt === null || $issueAt === '') {
            return now('America/Lima')->format('Y-m-d H:i:sP');
        }

        $text = trim((string) $issueAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $limaNow = now('America/Lima');
            return $text . ' ' . $limaNow->format('H:i:sP');
        }

        try {
            return Carbon::parse($text)->setTimezone('America/Lima')->format('Y-m-d H:i:sP');
        } catch (\Throwable $e) {
            return $issueAt;
        }
    }

    private function resolveDueAtForStorage($dueAt)
    {
        if ($dueAt === null || $dueAt === '') {
            return null;
        }

        $text = trim((string) $dueAt);
        if ($text === '' || in_array(strtolower($text), ['invalid date', 'undefined', 'null', 'nan'], true)) {
            return null;
        }

        $normalized = str_replace(',', '', $text);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
            return $normalized . ' 00:00:00';
        }

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $normalized);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        try {
            return Carbon::parse($normalized)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function documentKindCatalog()
    {
        $rows = collect($this->salesLookupService->listDocumentKindsCatalog());
        if (!$rows->isEmpty()) {
            return $rows
                ->map(function ($row) {
                    $code = (string) data_get($row, 'code', '');
                    $label = (string) data_get($row, 'label', '');
                    $meta = $this->documentKindMeta($code, $label);
                    return [
                        'id' => (int) data_get($row, 'id', 0),
                        'code' => $code,
                        'label' => $label,
                        'is_enabled' => (bool) data_get($row, 'is_enabled', true),
                        'base_kind' => $meta['base_kind'],
                        'kind_group' => $meta['kind_group'],
                        'note_target_kind' => $meta['note_target_kind'],
                    ];
                })
                ->values();
        }

        return collect([
            ['id' => 1, 'code' => 'QUOTATION', 'label' => 'Cotizacion', 'is_enabled' => true],
            ['id' => 2, 'code' => 'SALES_ORDER', 'label' => 'Pedido de Venta', 'is_enabled' => true],
            ['id' => 3, 'code' => 'INVOICE', 'label' => 'Factura', 'is_enabled' => true],
            ['id' => 4, 'code' => 'RECEIPT', 'label' => 'Boleta', 'is_enabled' => true],
            ['id' => 5, 'code' => 'CREDIT_NOTE', 'label' => 'Nota de Credito', 'is_enabled' => true],
            ['id' => 6, 'code' => 'DEBIT_NOTE', 'label' => 'Nota de Debito', 'is_enabled' => true],
        ])->map(function ($row) {
            $meta = $this->documentKindMeta((string) $row['code'], (string) $row['label']);
            return array_merge($row, $meta);
        })->values();
    }

    private function documentKindMeta(string $code, string $label = ''): array
    {
        $normalizedCode = strtoupper(trim($code));
        $normalizedLabel = strtoupper(trim($label));

        $baseKind = $normalizedCode;
        $kindGroup = 'TRIBUTARY';
        $noteTargetKind = null;

        if ($normalizedCode === 'QUOTATION' || $normalizedCode === 'SALES_ORDER') {
            $kindGroup = 'PRE_DOCUMENT';
        }

        if ($normalizedCode === 'CREDIT_NOTE' || strpos($normalizedCode, 'CREDIT_NOTE_') === 0) {
            $baseKind = 'CREDIT_NOTE';
            $kindGroup = 'NOTE_CREDIT';
        }

        if ($normalizedCode === 'DEBIT_NOTE' || strpos($normalizedCode, 'DEBIT_NOTE_') === 0) {
            $baseKind = 'DEBIT_NOTE';
            $kindGroup = 'NOTE_DEBIT';
        }

        if ($kindGroup === 'NOTE_CREDIT' || $kindGroup === 'NOTE_DEBIT') {
            $targetHint = $normalizedCode . ' ' . $normalizedLabel;
            if (strpos($targetHint, 'RECEIPT') !== false || strpos($targetHint, 'BOLETA') !== false) {
                $noteTargetKind = 'RECEIPT';
            } elseif (strpos($targetHint, 'INVOICE') !== false || strpos($targetHint, 'FACTURA') !== false) {
                $noteTargetKind = 'INVOICE';
            }
        }

        return [
            'base_kind' => $baseKind,
            'kind_group' => $kindGroup,
            'note_target_kind' => $noteTargetKind,
        ];
    }

    private function documentKindCodes(): array
    {
        return $this->documentKindCatalog()
            ->map(function ($row) {
                return (string) ($row['code'] ?? '');
            })
            ->filter(function ($code) {
                return $code !== '';
            })
            ->values()
            ->all();
    }

    private function fetchCustomerIdentityForSalesValidation(int $companyId, int $customerId)
    {
        return $this->salesLookupService->fetchCustomerIdentityForSalesValidation($companyId, $customerId);
    }

    private function documentKindRequiresRucCustomer(string $documentKind): bool
    {
        return $this->salesBusinessRuleService->documentKindRequiresRucCustomer($documentKind);
    }

    private function customerHasRucIdentity($customer): bool
    {
        return $this->salesBusinessRuleService->customerHasRucIdentity($customer);
    }

    private function resolveFallbackPaymentMethodId(int $companyId): ?int
    {
        return $this->salesLookupService->resolveFallbackPaymentMethodId($companyId);
    }

    private function hasActiveChildConversions(int $companyId, int $sourceDocumentId): bool
    {
        return $this->salesDocumentConversionService->alreadyConvertedToTarget($companyId, $sourceDocumentId, 'INVOICE')
            || $this->salesDocumentConversionService->alreadyConvertedToTarget($companyId, $sourceDocumentId, 'RECEIPT')
            || $this->salesDocumentConversionService->alreadyConvertedToTarget($companyId, $sourceDocumentId, 'SALES_ORDER');
    }

    private function reverseInventoryLedgerForDocument(
        int $companyId,
        int $documentId,
        ?string $voidAt,
        int $userId
    ): void {
        // Legacy path kept for compatibility; current void flow is delegated to use-case/services.
    }

    private function isDocumentKindRelatedToStockMovement(string $documentKind): bool
    {
        if ($documentKind === 'QUOTATION') {
            return false;
        }

        return in_array($documentKind, ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE', 'CREDIT_NOTE'], true);
    }

    private function findDocumentKindCatalogRowById(int $id): ?array
    {
        return $this->documentKindCatalog()
            ->first(function ($row) use ($id) {
                return (int) ($row['id'] ?? 0) === $id;
            });
    }

    private function findDocumentKindCatalogRowByCode(string $code): ?array
    {
        $normalizedCode = strtoupper(trim($code));

        return $this->documentKindCatalog()
            ->first(function ($row) use ($normalizedCode) {
                return strtoupper(trim((string) ($row['code'] ?? ''))) === $normalizedCode;
            });
    }


    private function stockDirectionForDocument(string $documentKind): int
    {
        if (in_array($documentKind, ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE'], true)) {
            return -1;
        }

        if ($documentKind === 'CREDIT_NOTE') {
            return 1;
        }

        return 0;
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = $this->salesLookupService->inventorySettingsForCompany($companyId);

        if (!$row) {
            return [
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
                'low_stock_alert_threshold' => 5,
            ];
        }

        return [
            'complexity_mode' => (string) ($row->complexity_mode ?? 'BASIC'),
            'inventory_mode' => (string) ($row->inventory_mode ?? 'KARDEX_SIMPLE'),
            'lot_outflow_strategy' => (string) ($row->lot_outflow_strategy ?? 'MANUAL'),
            'enable_inventory_pro' => (bool) ($row->enable_inventory_pro ?? false),
            'enable_lot_tracking' => (bool) ($row->enable_lot_tracking ?? false),
            'enable_expiry_tracking' => (bool) ($row->enable_expiry_tracking ?? false),
            'enable_advanced_reporting' => (bool) ($row->enable_advanced_reporting ?? false),
            'enable_graphical_dashboard' => (bool) ($row->enable_graphical_dashboard ?? false),
            'enable_location_control' => (bool) ($row->enable_location_control ?? false),
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
            'enforce_lot_for_tracked' => (bool) $row->enforce_lot_for_tracked,
            'low_stock_alert_threshold' => (int) ($row->low_stock_alert_threshold ?? 5),
        ];
    }

    private function isWorkshopMultiVehicleEnabledForRequest(Request $request, int $companyId): bool
    {
        $authUser = $request->attributes->get('auth_user');
        $branchIdRaw = $request->query('branch_id', $request->input('branch_id', $authUser->branch_id ?? null));
        $branchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;

        return $this->isWorkshopMultiVehicleEnabledForContext($companyId, $branchId);
    }

    private function isWorkshopMultiVehicleEnabledForContext(int $companyId, ?int $branchId): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_WORKSHOP_MULTI_VEHICLE', false);
    }

    private function isSalesOrderMultiPaymentEnabledForContext(int $companyId, ?int $branchId): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ORDER_MULTI_PAYMENT_ENABLED', false);
    }

    private function normalizeVehiclePlate(string $plate): string
    {
        $value = strtoupper(trim($plate));

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    private function isCommerceFeatureEnabled(int $companyId, string $featureCode): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, null, $featureCode, false);
    }

    private function isCommerceFeatureEnabledForContext(int $companyId, ?int $branchId, string $featureCode): bool
    {
        return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, $featureCode, false);
    }

    private function isCommerceFeatureEnabledForContextWithDefault(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled): bool
    {
        $resolved = $this->resolveFeatureResolutionForContext($companyId, $branchId, $featureCode, $defaultEnabled);

        return (bool) $resolved['is_enabled'];
    }

    private function isSellerActor(string $roleProfile, string $roleCode): bool
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

    private function isCashierActor(string $roleProfile, string $roleCode): bool
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

    private function canActorVoidDocuments(int $companyId, ?int $branchId, string $roleProfile, string $roleCode): bool
    {
        if ($this->isAdminActor($roleCode)) {
            return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_ADMIN', true);
        }

        if ($this->isCashierActor($roleProfile, $roleCode)) {
            return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_CASHIER', true);
        }

        if ($this->isSellerActor($roleProfile, $roleCode)) {
            return $this->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_SELLER', true);
        }

        return false;
    }

    private function isAdminActor(string $roleCode): bool
    {
        return $roleCode !== '' && strpos($roleCode, 'ADMIN') !== false;
    }

    private function isTechnicalActor(string $roleProfile, string $roleCode): bool
    {
        if ($this->isAdminActor($roleCode)) {
            return true;
        }

        if ($roleProfile === 'TECHNICAL' || $roleProfile === 'SYSTEM') {
            return true;
        }

        if ($roleCode === '') {
            return false;
        }

        return strpos($roleCode, 'SOPORTE') !== false
            || strpos($roleCode, 'TECH') !== false
            || strpos($roleCode, 'TECNIC') !== false
            || strpos($roleCode, 'SISTEM') !== false
            || strpos($roleCode, 'DEV') !== false;
    }

    private function canActorViewTaxBridgeDebug(int $companyId, ?int $branchId, string $roleProfile, string $roleCode): bool
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_TAX_BRIDGE_DEBUG_VIEW');
        if (!$featureRow || !(bool) ($featureRow->is_enabled ?? false)) {
            return false;
        }

        $allowedRoleCodes = $this->resolveFeatureAllowedRoleCodes($featureRow->config ?? null);
        if (!empty($allowedRoleCodes)) {
            return in_array($roleCode, $allowedRoleCodes, true)
                || in_array($roleProfile, $allowedRoleCodes, true);
        }

        return $this->isTechnicalActor($roleProfile, $roleCode);
    }

    private function resolveFeatureAllowedRoleCodes($rawConfig): array
    {
        $config = $this->decodeFeatureConfig($rawConfig);
        $rawRoles = $config['allowed_role_codes'] ?? null;

        if (is_string($rawRoles)) {
            $rawRoles = preg_split('/[;,\r\n]+/', $rawRoles) ?: [];
        }

        if (!is_array($rawRoles)) {
            return [];
        }

        $normalized = [];
        foreach ($rawRoles as $value) {
            $roleCode = strtoupper(trim((string) $value));
            if ($roleCode === '') {
                continue;
            }

            $normalized[$roleCode] = true;
        }

        return array_keys($normalized);
    }

    private function resolveAuthRoleContext(int $userId, int $companyId): array
    {
        $this->ensureCompanyRoleProfilesTable();
        return $this->salesLookupService->resolveAuthRoleContext($userId, $companyId);
    }

    private function ensureCompanyRoleProfilesTable(): void
    {
        $this->salesLookupService->ensureCompanyRoleProfilesTable();
    }

    private function resolveLineConversion(int $companyId, $product, array $item, ?int $itemUnitId): array
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

        $factor = null;
        if (isset($item['conversion_factor']) && (float) $item['conversion_factor'] > 0) {
            $factor = (float) $item['conversion_factor'];
        } else {
            $factor = $this->resolveConversionFactor($companyId, (int) $product->id, $lineUnitId, $baseUnitId);
        }

        if ($factor <= 0) {
            throw new \RuntimeException('Invalid conversion factor for product #' . $product->id);
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

    private function resolveConversionFactor(int $companyId, int $productId, int $lineUnitId, int $baseUnitId): float
    {
        if ($lineUnitId === $baseUnitId) {
            return 1.0;
        }

        $direct = $this->salesLookupService->findProductUomConversionFactor(
            $companyId,
            $productId,
            $lineUnitId,
            $baseUnitId
        );

        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }

        $inverse = $this->salesLookupService->findProductUomConversionFactor(
            $companyId,
            $productId,
            $baseUnitId,
            $lineUnitId
        );

        if ($inverse !== null && (float) $inverse > 0) {
            return 1 / (float) $inverse;
        }

        throw new \RuntimeException('Missing conversion from unit ' . $lineUnitId . ' to base unit ' . $baseUnitId . ' for product #' . $productId);
    }

    private function applyCurrentStockDelta(
        int $companyId,
        int $warehouseId,
        int $productId,
        float $delta,
        bool $allowNegativeStock
    ): void {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId;

        if (!array_key_exists($projectionKey, $this->stockProjection)) {
            $row = $this->salesLookupService->findCurrentStockRow($companyId, $warehouseId, $productId);

            $this->stockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        $current = $this->stockProjection[$projectionKey];
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for product #' . $productId);
        }

        $this->stockProjection[$projectionKey] = round($next, 8);
    }

    private function allocateOutboundLots(
        int $companyId,
        int $warehouseId,
        int $productId,
        float $qtyBase,
        float $conversionFactor,
        string $strategy,
        bool $allowNegativeStock,
        int $lineNumber
    ): array {
        $candidateLots = $this->salesLookupService->listCandidateOutboundLots(
            $companyId,
            $warehouseId,
            $productId,
            $strategy
        );

        if ($candidateLots->isEmpty()) {
            throw new \RuntimeException('No hay lotes disponibles para asignacion automatica en la linea ' . $lineNumber);
        }

        $remainingBase = round(max($qtyBase, 0), 8);
        $safeConversionFactor = max($conversionFactor, 0.00000001);
        $assignedLots = [];

        foreach ($candidateLots as $candidateLot) {
            if ($remainingBase <= 0.00000001) {
                break;
            }

            $availableBase = max(0, $this->projectedLotStock(
                $companyId,
                $warehouseId,
                $productId,
                (int) $candidateLot->id
            ));

            if ($availableBase <= 0.00000001) {
                continue;
            }

            $allocatedBase = min($availableBase, $remainingBase);

            $assignedLots[] = [
                'lot_id' => (int) $candidateLot->id,
                'qty' => round($allocatedBase / $safeConversionFactor, 8),
                'qty_base' => round($allocatedBase, 8),
            ];

            $remainingBase = round($remainingBase - $allocatedBase, 8);
        }

        if ($remainingBase > 0.00000001) {
            if (!$allowNegativeStock || empty($assignedLots)) {
                throw new \RuntimeException('Stock insuficiente por lotes para asignacion automatica en la linea ' . $lineNumber);
            }

            $lastIndex = count($assignedLots) - 1;
            $assignedLots[$lastIndex]['qty_base'] = round($assignedLots[$lastIndex]['qty_base'] + $remainingBase, 8);
            $assignedLots[$lastIndex]['qty'] = round($assignedLots[$lastIndex]['qty_base'] / $safeConversionFactor, 8);
        }

        return $assignedLots;
    }

    private function projectedLotStock(
        int $companyId,
        int $warehouseId,
        int $productId,
        int $lotId
    ): float {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;

        if (!array_key_exists($projectionKey, $this->lotStockProjection)) {
            $row = $this->salesLookupService->findCurrentStockByLotRow($companyId, $warehouseId, $productId, $lotId);

            $this->lotStockProjection[$projectionKey] = $row ? (float) $row->stock : 0.0;
        }

        return (float) $this->lotStockProjection[$projectionKey];
    }

    private function shouldAffectStock(string $documentKind, string $status): bool
    {
        return CommercialDocumentPolicy::shouldAffectStock($documentKind, $status);
    }

    private function applyLotStockDelta(
        int $companyId,
        int $warehouseId,
        int $productId,
        int $lotId,
        float $delta,
        bool $allowNegativeStock
    ): void {
        $projectionKey = $companyId . ':' . $warehouseId . ':' . $productId . ':' . $lotId;
        $current = $this->projectedLotStock($companyId, $warehouseId, $productId, $lotId);
        $next = $current + $delta;

        if (!$allowNegativeStock && $next < -0.00000001) {
            throw new \RuntimeException('Insufficient stock for lot #' . $lotId);
        }

        $this->lotStockProjection[$projectionKey] = round($next, 8);
    }

    /**
     * Endpoint para reintentar envÃ­o tributario de un documento.
     * Permite reenviar documentos que tuvieron rechazo o error a SUNAT.
     * 
     * Route: PUT /api/sales/commercial-documents/{id}/retry-tax-bridge
     */
    public function taxBridgeDebug(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $document = $this->salesLookupService->findTaxBridgeDocumentForDebug($companyId, $id);

        if (!$document) {
            return response()->json([
                'message' => 'Documento no encontrado',
            ], 404);
        }

        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        $branchId = $document->branch_id !== null ? (int) $document->branch_id : null;
        if (!$this->canActorViewTaxBridgeDebug($companyId, $branchId, $roleProfile, $roleCode)) {
            return response()->json([
                'message' => 'No autorizado para ver el detalle tecnico del puente SUNAT',
            ], 403);
        }

        return response()->json([
            'message' => 'Tax bridge debug loaded',
            'document_id' => (int) $document->id,
            'debug' => $this->taxBridgeService->getLastDispatchDebug($companyId, (int) $document->id),
        ]);
    }

    public function retryTaxBridgeSend(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->taxBridgeService->retry($companyId, $id);
            $diagnostic = $this->taxBridgeService->summarizeBridgeDiagnostic($result['response'] ?? null);
            $status = strtoupper((string) ($result['status'] ?? ''));
            $isWafBlocked = (bool) ($result['waf_blocked'] ?? false);

            if ($isWafBlocked || $status === 'WAF_BLOCKED') {
                return response()->json([
                    'message' => 'Tax bridge retry blocked by Imunify360 bot-protection. Solicite whitelist de IP/automatizacion en el puente SUNAT.',
                    'document_id' => $id,
                    'sunat_status' => $result['status'],
                    'sunat_status_label' => $result['label'],
                    'bridge_http_code' => $result['bridge_http_code'] ?? null,
                    'bridge_response' => $result['response'] ?? null,
                    'sunat_error_code' => $diagnostic['code'] ?? null,
                    'sunat_error_message' => $diagnostic['message'] ?? null,
                    'debug' => $result['debug'] ?? null,
                ], 422);
            }

            if (in_array($status, ['REJECTED', 'ERROR', 'HTTP_ERROR'], true)) {
                return response()->json([
                    'message' => 'Tax bridge retry failed',
                    'document_id' => $id,
                    'sunat_status' => $result['status'],
                    'sunat_status_label' => $result['label'],
                    'bridge_http_code' => $result['bridge_http_code'] ?? null,
                    'bridge_response' => $result['response'] ?? null,
                    'sunat_error_code' => $diagnostic['code'] ?? null,
                    'sunat_error_message' => $diagnostic['message'] ?? null,
                    'debug' => $result['debug'] ?? null,
                ], 422);
            }

            return response()->json([
                'message' => 'Tax bridge retry sent successfully',
                'document_id' => $id,
                'sunat_status' => $result['status'],
                'sunat_status_label' => $result['label'],
                'bridge_http_code' => $result['bridge_http_code'] ?? null,
                'bridge_response' => $result['response'] ?? null,
                'sunat_error_code' => $diagnostic['code'] ?? null,
                'sunat_error_message' => $diagnostic['message'] ?? null,
                'debug' => $result['debug'] ?? null,
            ], 200);
        } catch (TaxBridgeException $e) {
            $debug = $this->taxBridgeService->getLastDispatchDebug($companyId, $id);

            return response()->json([
                'message' => $e->getMessage(),
                'debug' => $debug,
            ], $e->httpStatus());
        }
    }

    public function sunatVoidCommunication(SunatVoidCommunicationRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();

        try {
            $result = $this->taxBridgeService->sendVoidCommunication($companyId, $id, $payload['reason'] ?? null);
            $diagnostic = $this->taxBridgeService->summarizeBridgeDiagnostic($result['response'] ?? null);

            if (($result['status'] ?? '') === 'ACCEPTED') {
                $this->voidCommercialDocumentUseCase->execute($authUser, $companyId, $id, [
                    'reason' => $payload['reason'] ?? 'Comunicacion de baja SUNAT',
                    'notes' => $payload['notes'] ?? 'Anulado por comunicacion de baja SUNAT',
                    'void_at' => now()->toDateTimeString(),
                    'sunat_void_status' => 'ACCEPTED',
                ]);
            }

            return response()->json([
                'message' => 'Comunicacion de baja SUNAT procesada',
                'document_id' => $id,
                'sunat_void_status' => $result['status'] ?? '',
                'sunat_void_label' => $result['label'] ?? '',
                'bridge_http_code' => $result['bridge_http_code'] ?? null,
                'bridge_response' => $result['response'] ?? null,
                'sunat_error_code' => $diagnostic['code'] ?? null,
                'sunat_error_message' => $diagnostic['message'] ?? null,
                'void_number' => $result['void_number'] ?? null,
                'debug' => $result['debug'] ?? null,
            ], 200);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        } catch (TaxBridgeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }
    }

    public function previewTaxBridgePayload(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $preview = $this->taxBridgeService->preview($companyId, $id);

            return response()->json([
                'message' => 'Tax bridge payload preview generated successfully',
                'document_id' => $id,
                'bridge_mode' => $preview['bridge_mode'],
                'endpoint' => $preview['endpoint'],
                'method' => $preview['method'],
                'content_type' => $preview['content_type'],
                'form_key' => $preview['form_key'],
                'payload' => $preview['payload'],
                'debug' => $preview,
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }
    }

    public function downloadSunatXml(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->taxBridgeService->downloadDocument($companyId, $id, 'dowload_xml');

            return response($result['body'], 200)
                ->header('Content-Type', $result['content_type'])
                ->header('Content-Disposition', 'attachment; filename="' . addslashes($result['filename']) . '"')
                ->header('X-Bridge-Endpoint', $result['endpoint'])
                ->header('X-Bridge-Method', 'GET')
                ->header('X-Bridge-Http-Status', (string) ($result['http_status'] ?? 200))
                ->header('X-Bridge-Content-Type', (string) ($result['bridge_content_type'] ?? ''));
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error al descargar XML: ' . $e->getMessage()], 500);
        }
    }

    public function downloadSunatCdr(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->taxBridgeService->downloadDocument($companyId, $id, 'dowload_cdr');

            return response($result['body'], 200)
                ->header('Content-Type', $result['content_type'])
                ->header('Content-Disposition', 'attachment; filename="' . addslashes($result['filename']) . '"')
                ->header('X-Bridge-Endpoint', $result['endpoint'])
                ->header('X-Bridge-Method', 'GET')
                ->header('X-Bridge-Http-Status', (string) ($result['http_status'] ?? 200))
                ->header('X-Bridge-Content-Type', (string) ($result['bridge_content_type'] ?? ''));
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error al descargar CDR: ' . $e->getMessage()], 500);
        }
    }

    private function resolveCompanyLogoUrl($logoPath): ?string
    {
        $raw = trim((string) ($logoPath ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $raw) === 1) {
            $resolved = $this->rewriteLocalAbsoluteUrlToRequestHost($raw);
            return $this->appendLocalLogoVersion($resolved, null);
        }

        $normalized = str_replace('\\', '/', $raw);
        if (preg_match('/^https?:\/\//i', $normalized) === 1) {
            $pathFromUrl = parse_url($normalized, PHP_URL_PATH);
            $normalized = $pathFromUrl !== null ? (string) $pathFromUrl : $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        if ($normalized === '') {
            return null;
        }

        $resolved = url('/storage/' . $normalized);
        try {
            if (\Storage::disk('public')->exists($normalized)) {
                $resolved = url('/storage/' . $normalized);
            }
        } catch (\Throwable $e) {
            // Ignorar para evitar ocultar logo por una validacion temporal del storage.
        }

        $resolved = $this->rewriteLocalAbsoluteUrlToRequestHost($resolved);
        return $this->appendLocalLogoVersion($resolved, $normalized);
    }

    private function rewriteLocalAbsoluteUrlToRequestHost(string $url): string
    {
        if (!$this->isLocalEnvironment()) {
            return $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0'], true)) {
            return $url;
        }

        $request = request();
        if (!$request) {
            return $url;
        }

        $requestHost = trim((string) $request->getHost());
        if ($requestHost === '') {
            return $url;
        }

        $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?? $request->getScheme());
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $port = parse_url($url, PHP_URL_PORT);
        if ($port === null) {
            $port = $request->getPort();
        }

        $portSuffix = '';
        if (is_int($port) && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $portSuffix = ':' . $port;
        }

        $rebuilt = $scheme . '://' . $requestHost . $portSuffix . $path;
        if ($query !== '') {
            $rebuilt .= '?' . $query;
        }

        return $rebuilt;
    }

    private function appendLocalLogoVersion(string $url, ?string $normalizedPath): string
    {
        if (!$this->isLocalEnvironment() || $normalizedPath === null || trim($normalizedPath) === '') {
            return $url;
        }

        try {
            $absolutePath = storage_path('app/public/' . ltrim($normalizedPath, '/'));
            if (!is_file($absolutePath)) {
                return $url;
            }

            $version = (string) @filemtime($absolutePath);
            if ($version === '' || $version === '0') {
                return $url;
            }

            return strpos($url, '?') === false
                ? $url . '?v=' . $version
                : $url . '&v=' . $version;
        } catch (\Throwable $e) {
            return $url;
        }
    }

    private function isLocalEnvironment(): bool
    {
        return strtolower((string) env('APP_ENV', 'production')) === 'local';
    }

    private function normalizeCompanyLogoStoragePath($logoPath): ?string
    {
        $raw = trim((string) ($logoPath ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $raw);
        if (preg_match('/^https?:\/\//i', $normalized) === 1) {
            $pathFromUrl = parse_url($normalized, PHP_URL_PATH);
            $normalized = $pathFromUrl !== null ? (string) $pathFromUrl : $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        if (str_starts_with($normalized, 'public/')) {
            $normalized = ltrim(substr($normalized, strlen('public/')), '/');
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function publicStorageLogoExists(string $normalizedPath): bool
    {
        try {
            return \Storage::disk('public')->exists($normalizedPath);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function companyLogoDataUriFromPublicStorage(string $normalizedPath): ?string
    {
        try {
            if (!\Storage::disk('public')->exists($normalizedPath)) {
                return null;
            }

            $absolutePath = \Storage::disk('public')->path($normalizedPath);
            $binary = @file_get_contents($absolutePath);
            if ($binary === false || $binary === '') {
                return null;
            }

            $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
            $mimeType = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => '',
            };

            if ($mimeType === '') {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detected = @finfo_file($finfo, $absolutePath);
                    @finfo_close($finfo);
                    $mimeType = is_string($detected) ? trim($detected) : '';
                }
            }

            if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
                $mimeType = 'image/png';
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($binary);
        } catch (\Throwable $e) {
            return null;
        }
    }
}


